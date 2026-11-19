<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AttendanceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CheckInRequest;
use App\Http\Requests\Api\V1\CheckOutRequest;
use App\Http\Requests\Api\V1\LivenessChallengeRequest;
use App\Http\Resources\Api\V1\AttendanceResource;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\CompanyLocation;
use App\Models\Employee;
use App\Models\Freelancer;
use App\Models\Intern;
use App\Models\LeaveRequest;
use App\Models\ShiftSchedule;
use App\Models\User;
use App\Services\Attendance\AttendanceCheckInput;
use App\Services\Attendance\AttendanceRuleException;
use App\Services\Attendance\WorkSession;
use App\Services\AttendanceCheckService;
use App\Services\AttendanceFigures;
use App\Services\DataAccessService;
use App\Services\Face\FaceServiceUnavailable;
use App\Services\Face\FaceVerifier;
use App\Services\FileNamingService;
use App\Services\GeofenceService;
use App\Services\OffScheduleCheckInService;
use App\Services\OutletRosterService;
use App\Services\SessionResolver;
use App\Services\UploadStorageException;
use App\Services\WorkScheduleService;
use App\Support\StorageUrl;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class AttendanceApiController extends Controller
{
    /** How far back the Today card looks for a shift whose check-out was forgotten. */
    private const UNCLOSED_LOOKBACK_DAYS = 7;

    public function livenessChallenge(LivenessChallengeRequest $request, AttendanceCheckService $attendanceCheck): JsonResponse
    {
        // Refused before the worker spends GPS, poses and uploads on a check-in the leave would refuse anyway.
        if ($request->validated('purpose') === AttendanceCheckService::PURPOSE_CHECK_IN && $this->isOnApprovedLeaveToday($request->user())) {
            return $this->ruleError(new AttendanceRuleException(AttendanceCheckService::ON_LEAVE_MESSAGE, 409));
        }

        try {
            return response()->json($attendanceCheck->livenessChallenge($request->user(), $request->validated('purpose')));
        } catch (AttendanceRuleException $exception) {
            return $this->ruleError($exception);
        }
    }

    public function checkIn(CheckInRequest $request, AttendanceCheckService $attendanceCheck): JsonResponse
    {
        return $this->runCheck($request, AttendanceCheckService::PURPOSE_CHECK_IN, $attendanceCheck);
    }

    public function checkOut(CheckOutRequest $request, AttendanceCheckService $attendanceCheck): JsonResponse
    {
        return $this->runCheck($request, AttendanceCheckService::PURPOSE_CHECK_OUT, $attendanceCheck);
    }

    /**
     * Store the uploaded selfie and liveness frames, then let the shared service apply the policy.
     *
     * The service owns the stored files from here: it removes those of a refused attempt and those
     * the saved record does not use.
     */
    private function runCheck(CheckInRequest|CheckOutRequest $request, string $purpose, AttendanceCheckService $attendanceCheck): JsonResponse
    {
        $user = $request->user();
        $isCheckIn = $purpose === AttendanceCheckService::PURPOSE_CHECK_IN;
        $disk = config('filesystems.default');
        $profile = $user->employee ?? $user->intern ?? $user->freelancer;
        $identifier = $profile?->employee_code ?? $profile?->nik ?? (string) ($profile?->id ?? $user->id);

        $photoPath = null;
        $framePaths = [];

        try {
            if ($request->hasFile('photo')) {
                $photoPath = FileNamingService::storeUploadedFile(
                    $request->file('photo'),
                    'attendance/photos',
                    $isCheckIn ? 'ATT_IN' : 'ATT_OUT',
                    $identifier,
                    $disk
                );
            }

            foreach ($request->file('liveness_frames', []) as $frame) {
                $framePaths[] = FileNamingService::storeUploadedFile(
                    $frame,
                    'attendance/liveness',
                    $isCheckIn ? 'LIVE_IN' : 'LIVE_OUT',
                    $identifier,
                    $disk
                );
            }
        } catch (UploadStorageException) {
            // Files written before the failure belong to no record; remove them before asking for a resend.
            $stored = array_values(array_filter([$photoPath, ...$framePaths]));
            if ($stored !== []) {
                Storage::disk($disk)->delete($stored);
            }

            return $this->ruleError(new AttendanceRuleException('Foto gagal disimpan di server. Coba kirim lagi beberapa saat.', 503, 'photo'));
        }

        $input = new AttendanceCheckInput(
            latitude: $request->filled('latitude') ? (float) $request->input('latitude') : null,
            longitude: $request->filled('longitude') ? (float) $request->input('longitude') : null,
            accuracy: $request->filled('accuracy') ? (float) $request->input('accuracy') : null,
            isMocked: $request->boolean('is_mocked'),
            photoPath: $photoPath,
            livenessChallengeId: $request->input('liveness_challenge_id'),
            livenessFramePaths: $framePaths,
            coverShiftId: $isCheckIn && $request->filled('cover_shift_id') ? (int) $request->input('cover_shift_id') : null,
            replacesWorker: $isCheckIn && $request->filled('replaces_worker') ? (string) $request->input('replaces_worker') : null,
            scheduleId: $isCheckIn && $request->filled('schedule_id') ? (int) $request->input('schedule_id') : null,
            segment: $isCheckIn && $request->filled('segment') ? (int) $request->input('segment') : null,
            coverLocationId: $isCheckIn && $request->filled('cover_location_id') ? (int) $request->input('cover_location_id') : null,
        );

        try {
            $result = $isCheckIn
                ? $attendanceCheck->checkIn($user, $input)
                : $attendanceCheck->checkOut($user, $input);
        } catch (AttendanceRuleException $exception) {
            return $this->ruleError($exception);
        }

        return response()->json([
            'message' => $result->message,
            'attendance' => new AttendanceResource($result->attendance),
            'requires_approval' => $result->requiresApproval,
            'approval_reasons' => $result->approvalReasons,
        ]);
    }

    /**
     * What the app offers for "Masuk di luar jadwal": whether a check-in now is outside the worker's
     * schedule, the active shifts of the company to cover, and the colleagues rostered that date.
     *
     * The date is today, or yesterday when the optional cover_shift_id is a night shift covered after
     * midnight: the check-in is then booked to yesterday, so the colleague it replaces is one rostered yesterday.
     */
    public function coverOptions(Request $request, OffScheduleCheckInService $offSchedule, AttendanceCheckService $attendanceCheck): JsonResponse
    {
        $validated = $request->validate(
            ['cover_shift_id' => ['nullable', 'integer', 'min:1']],
            ['cover_shift_id.integer' => 'Shift yang dipilih tidak valid.', 'cover_shift_id.min' => 'Shift yang dipilih tidak valid.'],
        );
        $user = $request->user();
        $profile = $user->employee ?? $user->intern ?? $user->freelancer;

        if (! $profile) {
            return response()->json(['message' => 'Profil pengguna tidak ditemukan.'], 422);
        }

        $coverShiftId = isset($validated['cover_shift_id']) ? (int) $validated['cover_shift_id'] : null;

        return response()->json($offSchedule->options($profile, $attendanceCheck->coverBusinessDate($user, $coverShiftId)));
    }

    private function ruleError(AttendanceRuleException $exception): JsonResponse
    {
        return response()->json([
            'message' => $exception->getMessage(),
            'errors' => [$exception->field => [$exception->getMessage()]],
        ], $exception->status);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        // Own records, the workers inside the user's data access and whoever worked at an outlet in it:
        // the same rows every team report counts.
        $query = DataAccessService::scopeWorkerOrOutletRecords(Attendance::query()->with(Attendance::workerEagerLoads()), $user, Attendance::workerRelations());

        if ($request->has('start_date') && $request->has('end_date')) {
            $this->whereDateRange($query, $request->input('start_date'), $request->input('end_date'));
        } elseif ($request->has('month') && $request->has('year')) {
            $query->whereMonth('date', $request->input('month'))
                ->whereYear('date', $request->input('year'));
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        // mine=1 keeps only the signed-in worker's own rows (the correction form's session picker).
        if ($request->boolean('mine')) {
            $query->byWorker($user);
        }

        // Many rows share a date (a team, split shifts): the id keeps the pages disjoint.
        $attendances = $query->orderByDesc('date')->orderByDesc('segment')->orderByDesc('id')->paginate(20);

        return response()->json([
            'data' => AttendanceResource::collection($attendances->items()),
            'pagination' => [
                'current_page' => $attendances->currentPage(),
                'last_page' => $attendances->lastPage(),
                'per_page' => $attendances->perPage(),
                'total' => $attendances->total(),
            ],
        ]);
    }

    /**
     * Rows dated from the start day up to and including the end day, as a half-open range so the end day
     * also matches where dates are stored with a time (SQLite). Dates that cannot be read match nothing.
     *
     * @param  Builder<Attendance>  $query
     */
    private function whereDateRange(Builder $query, mixed $start, mixed $end): void
    {
        try {
            $from = is_string($start) && $start !== '' ? Carbon::parse($start)->toDateString() : null;
            $until = is_string($end) && $end !== '' ? Carbon::parse($end)->addDay()->toDateString() : null;
        } catch (InvalidFormatException) {
            $from = $until = null;
        }

        if ($from === null || $until === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('date', '>=', $from)->where('date', '<', $until);
    }

    public function show(int $id, Request $request): JsonResponse
    {
        $user = $request->user();
        $attendance = Attendance::with(['employee', 'intern', 'freelancer', 'approvalSteps'])->find($id);

        if (! $attendance) {
            return response()->json(['message' => 'Data presensi tidak ditemukan.'], 404);
        }

        $inScope = DataAccessService::scopeWorkerOrOutletRecords(Attendance::query(), $user, Attendance::workerRelations())->whereKey($attendance->getKey())->exists();

        if (! $inScope && ! ApprovalApiController::canOpen($attendance, $user)) {
            return response()->json(['message' => 'Anda tidak memiliki akses ke presensi ini.'], 403);
        }

        return response()->json([
            'data' => new AttendanceResource($attendance),
        ]);
    }

    /**
     * Set or replace the worker's profile photo. The same photo is the master face that face recognition
     * and liveness compare check-in selfies with, so the worker sets both from one place.
     */
    public function registerMasterFace(Request $request, FaceVerifier $faceVerifier): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'file', 'image', 'mimes:jpeg,png,jpg,webp', 'max:5120'],
        ], [
            'photo.required' => 'Foto profil wajib diunggah.',
            'photo.uploaded' => 'Foto profil gagal terunggah. Periksa koneksi lalu coba lagi.',
            'photo.file' => 'Foto profil harus berupa berkas gambar.',
            'photo.image' => 'Foto profil harus berupa berkas gambar.',
            'photo.mimes' => 'Foto profil harus berformat jpeg, png, jpg, atau webp.',
            'photo.max' => 'Foto profil maksimal berukuran 5MB.',
        ]);

        $user = $request->user();
        $profile = $user->employee ?? $user->intern ?? $user->freelancer;

        if (! $profile) {
            return response()->json(['message' => 'Profil pengguna tidak ditemukan.'], 422);
        }

        $file = $request->file('photo');
        if (! $file || ! $file->isValid()) {
            return response()->json(['message' => 'File foto profil tidak valid atau gagal disimpan di temporary server.'], 422);
        }

        $faceProblem = $this->profilePhotoFaceProblem($faceVerifier, $file->getRealPath());
        if ($faceProblem !== null) {
            return response()->json(['message' => $faceProblem, 'errors' => ['photo' => [$faceProblem]]], 422);
        }

        $identifier = $profile->employee_code ?? $profile->nik ?? (string) ($profile->id ?? $user->id);

        $photoPath = FileNamingService::storeUploadedFile(
            $file,
            'master-faces',
            'MASTER_FACE',
            $identifier,
            config('filesystems.default')
        );

        $profile->update([
            'master_face_photo' => $photoPath,
            'master_face_verified_at' => now(),
        ]);

        $photoUrl = StorageUrl::for($photoPath);

        return response()->json([
            'message' => 'Foto profil berhasil diperbarui.',
            'data' => [
                'master_face_photo' => $photoUrl,
                'master_face_verified_at' => $profile->master_face_verified_at->toISOString(),
            ],
        ]);
    }

    /**
     * Why a profile photo cannot serve as the master face, or null when it can. Only a driver that does
     * real face recognition can tell; the built-in pixel comparison cannot, and an unreachable face
     * service does not stop the worker from setting a photo.
     */
    private function profilePhotoFaceProblem(FaceVerifier $faceVerifier, string|false $path): ?string
    {
        if ($path === false || ! $faceVerifier->isAccurate()) {
            return null;
        }

        try {
            $faces = $faceVerifier->detect($path)->faces;
        } catch (FaceServiceUnavailable) {
            return null;
        }

        return match (true) {
            $faces === 0 => 'Wajah tidak terlihat di foto. Foto profil juga dipakai untuk verifikasi wajah saat absen, jadi gunakan foto wajah Anda sendiri yang jelas.',
            $faces > 1 => 'Ada lebih dari satu wajah di foto. Gunakan foto yang hanya berisi wajah Anda.',
            default => null,
        };
    }

    public function today(Request $request, AttendanceCheckService $attendanceCheck): JsonResponse
    {
        $user = $request->user();
        $profile = $user->employee ?? $user->intern ?? $user->freelancer;

        if (! $profile) {
            return response()->json(['message' => 'Profil pengguna tidak ditemukan.'], 422);
        }

        $workSchedule = app(WorkScheduleService::class);
        $resolver = app(SessionResolver::class);
        // The session the worker is on now (the same one a check-in or check-out tap acts on): the open row that
        // can still be checked out (a shift of yesterday after midnight included), else the next session of the
        // day. On a split shift or a second schedule, the card starts fresh once the earlier session is closed.
        $current = $resolver->current($profile, now(), $attendanceCheck->closableAttendance($user));
        /** @var WorkSession|null $session */
        $session = $current['session'];
        $attendance = $current['attendance']?->loadMissing(['employee', 'intern', 'freelancer', 'location']);
        $date = $current['date'];
        // Without a session (a day off, or no schedule) the card shows the day's own hours: the company hours.
        $dayHours = $session ? null : $workSchedule->resolve($profile, today());
        $shift = $session?->shift;
        $hasSessionToday = $resolver->sessionsFor($profile, today()) !== [];

        $dayRows = Attendance::byWorker($user)->with(['employee', 'intern', 'freelancer'])->whereDate('date', $date->toDateString())->get()
            ->sortBy(fn (Attendance $row): array => [$row->sched_start_at?->getTimestamp() ?? $row->check_in?->getTimestamp() ?? 0, (int) $row->schedule_sequence, (int) $row->segment])
            ->values();
        $todayRows = $date->isSameDay(today()) ? $dayRows : Attendance::byWorker($user)->whereDate('date', today())->get();
        $isOnLeave = $todayRows->contains(fn (Attendance $row): bool => (int) ($row->schedule_sequence ?: 1) === 1 && (int) $row->segment === 1 && $this->isApprovedLeaveRow($row));
        $unclosed = $this->unclosedRows($user, $attendance, $attendanceCheck);
        $pendingUnclosedDates = $this->pendingCorrectionDates($user, $unclosed);

        return response()->json([
            'date' => today()->toDateString(),
            // Today is covered by an approved leave: check-in would be refused, so the app does not offer it.
            'is_on_leave' => $isOnLeave,
            'has_checked_in' => $attendance?->check_in !== null,
            'has_checked_out' => $attendance?->check_out !== null,
            'attendance' => $attendance ? new AttendanceResource($attendance) : null,
            // Every attendance of the session's business date (each part and each schedule), ordered by start.
            'attendances' => AttendanceResource::collection($dayRows),
            // The current session's hours: a dated schedule, else the default shift, else the company hours.
            'schedule' => [
                'shift' => $shift ? ['id' => (int) $shift->id, 'name' => $shift->name, 'code' => $shift->code, 'talenta_code' => $shift->talenta_code] : null,
                'is_day_off' => $session ? false : (bool) $dayHours['is_day_off'],
                'segment' => $session->segment ?? 1,
                'segment_count' => $session->segmentCount ?? 1,
                // Every segment of the session's shift, for showing "07:45–13:00 & 17:45–22:00".
                'segments' => $shift?->segments() ?? [],
                'start_time' => $session ? $session->startAt->format('H:i') : substr((string) $dayHours['start_time'], 0, 5),
                'end_time' => $session ? $session->endAt->format('H:i') : substr((string) $dayHours['end_time'], 0, 5),
                'late_tolerance_minutes' => $session->toleranceMinutes ?? (int) $dayHours['late_tolerance_minutes'],
                'outlet' => $this->outletGeofence($session ? $session->location : $dayHours['location']),
                'section' => $session ? $session->section : $dayHours['section'],
                // Not expected to work (day off, or no schedule): a check-in needs approval and may cover a colleague.
                'is_off_schedule' => $session ? $session->source === 'cover' : ! $hasSessionToday,
            ],
            // Where check-out is accepted: the outlet the open attendance was checked in at.
            'check_out_outlet' => $attendance?->check_in !== null && $attendance->check_out === null
                ? $this->outletGeofence($attendance->location)
                : null,
            // An earlier shift was never checked out: the app points to a correction, today's check-in stays open.
            'unclosed_previous' => $this->unclosedPrevious($unclosed, $pendingUnclosedDates),
            // Every open row that needs a correction, oldest first (an earlier day and an earlier session of today alike).
            'unclosed_list' => $unclosed->reverse()->map(fn (Attendance $row): array => $this->unclosedEntry($row, $pendingUnclosedDates) + ['schedule_sequence' => (int) ($row->schedule_sequence ?: 1)])->values()->all(),
            // Every schedule part of the session's business date, ordered by start.
            'schedules' => $this->todaySchedules($profile, $date, $current['sessions'], $session, $isOnLeave && $date->isSameDay(today()), $resolver),
            // The worker has a schedule today that they are expected to work (not a day off, not a weekend of company hours).
            'is_scheduled' => $isScheduledToday = $this->isScheduledOn($resolver, $profile, today()),
            // The business date the card describes (the current session's: yesterday while last night's shift
            // is still on after midnight), and whether the worker is expected to work on it. The app decides
            // "Tidak ada jadwal hari ini" from these, so the notice agrees with the hours the card shows.
            'session_date' => $date->toDateString(),
            'is_session_scheduled' => $date->isSameDay(today()) ? $isScheduledToday : $this->isScheduledOn($resolver, $profile, $date),
            'day_totals' => $this->dayTotals($dayRows),
        ]);
    }

    /**
     * Whether the worker has a schedule on the date that they are expected to work (not a day off, not a
     * weekend of company hours).
     */
    private function isScheduledOn(SessionResolver $resolver, Employee|Intern|Freelancer $profile, CarbonInterface $date): bool
    {
        return collect($resolver->sessionsFor($profile, $date))->contains(fn (WorkSession $session): bool => $session->scheduleId !== null || $session->isExpected);
    }

    /**
     * The schedule parts of the date with the attendance of each, for the newer app's list of the day.
     *
     * @param  list<array{session: WorkSession, attendance: Attendance|null}>  $sessions
     * @return list<array<string, mixed>>
     */
    private function todaySchedules(Employee|Intern|Freelancer $profile, CarbonInterface $date, array $sessions, ?WorkSession $current, bool $onLeave, SessionResolver $resolver): array
    {
        if ($sessions === []) {
            $dayOff = ShiftSchedule::query()
                ->where(app(WorkScheduleService::class)->workerColumn($profile), $profile->getKey())
                ->whereDate('date', $date->toDateString())
                ->where('is_day_off', true)
                ->first();

            return $dayOff ? [[
                'schedule_id' => (int) $dayOff->id, 'sequence' => (int) ($dayOff->sequence ?: 1), 'segment' => 1, 'is_current' => false,
                'is_day_off' => true, 'shift' => null, 'outlet' => null, 'section' => $dayOff->section, 'start_time' => null, 'end_time' => null,
                'crosses_midnight' => false, 'late_tolerance_minutes' => null, 'normal_minutes' => null,
                'state' => $onLeave ? 'cuti' : OutletRosterService::STATE_DAY_OFF, 'check_out_closes_at' => null, 'attendance' => null,
            ]] : [];
        }

        return array_map(function (array $entry) use ($current, $onLeave, $resolver): array {
            /** @var WorkSession $session */
            $session = $entry['session'];
            /** @var Attendance|null $row */
            $row = $entry['attendance']?->loadMissing(['employee', 'intern', 'freelancer']);
            $closesAt = $resolver->checkOutClosesAt($row?->check_in ? $row : $session);

            return [
                'schedule_id' => $session->scheduleId,
                'sequence' => $session->sequence,
                'segment' => $session->segment,
                'is_current' => $current !== null && $current->date->equalTo($session->date) && $current->key() === $session->key(),
                'is_day_off' => false,
                'shift' => $session->shift ? ['id' => (int) $session->shift->id, 'name' => $session->shift->name, 'code' => $session->shift->code] : null,
                'outlet' => $this->outletGeofence($session->location),
                'section' => $session->section,
                'start_time' => $session->startAt->format('H:i'),
                'end_time' => $session->endAt->format('H:i'),
                'crosses_midnight' => $session->crossesMidnight(),
                'late_tolerance_minutes' => $session->toleranceMinutes,
                'normal_minutes' => $session->normalMinutes,
                'state' => $this->sessionState($session, $row, $onLeave),
                'check_out_closes_at' => $closesAt?->toIso8601String(),
                'attendance' => $row ? new AttendanceResource($row) : null,
            ];
        }, $sessions);
    }

    /**
     * The state of one session in the roster vocabulary (libur > cuti > ditolak > menunggu > belum/nanti >
     * pulang > terlambat > hadir).
     */
    private function sessionState(WorkSession $session, ?Attendance $row, bool $onLeave): string
    {
        return match (true) {
            $onLeave && $row?->check_in === null => 'cuti',
            $row?->check_in === null => now()->greaterThanOrEqualTo($session->startAt) ? OutletRosterService::STATE_MISSING : OutletRosterService::STATE_UPCOMING,
            $row->status === AttendanceStatus::Rejected => OutletRosterService::STATE_REJECTED,
            $row->status === AttendanceStatus::PendingApproval => OutletRosterService::STATE_WAITING,
            $row->check_out !== null => OutletRosterService::STATE_DONE,
            $row->status === AttendanceStatus::Late => OutletRosterService::STATE_LATE,
            default => OutletRosterService::STATE_PRESENT,
        };
    }

    /**
     * Worked, automatic overtime and late minutes of the day's attendance, rejected rows left out: the
     * figures the reports count for it.
     *
     * @param  Collection<int, Attendance>  $rows
     * @return array{worked_minutes: int, overtime_minutes: int, late_minutes: int}
     */
    private function dayTotals(Collection $rows): array
    {
        $counted = $rows->filter(fn (Attendance $row): bool => $row->check_in !== null && $row->status !== AttendanceStatus::Rejected)->values();
        AttendanceFigures::preload($counted);
        $totals = ['worked_minutes' => 0, 'overtime_minutes' => 0, 'late_minutes' => 0];

        foreach ($counted as $row) {
            $figures = AttendanceFigures::forAttendance($row);
            $totals['worked_minutes'] += $figures['worked_minutes'];
            $totals['overtime_minutes'] += $figures['overtime_minutes'];
            $totals['late_minutes'] += $figures['reported_late_minutes'];
        }

        return $totals;
    }

    /**
     * The open rows of the last UNCLOSED_LOOKBACK_DAYS days (today included) that were checked in but never
     * checked out and are not the one offered for check-out now, nor still closable live (a shift still inside
     * its check-out window is checked out as usual), newest first. Every segment counts, so a forgotten
     * segment 1 of a split shift is found too, and a forgotten shift before a day off still shows after it.
     * The worker asks for a correction with the real check-out time; checking out now would record the wrong time.
     *
     * @return Collection<int, Attendance>
     */
    private function unclosedRows(User $user, ?Attendance $shown, AttendanceCheckService $attendanceCheck): Collection
    {
        return Attendance::byWorker($user)
            ->with('shift')
            // Today too: an earlier session of a several-schedule day left open once the worker checked in elsewhere.
            ->whereDate('date', '<=', today())
            ->whereDate('date', '>=', today()->subDays(self::UNCLOSED_LOOKBACK_DAYS))
            ->whereNotNull('check_in')
            ->whereNull('check_out')
            ->where('status', '!=', AttendanceStatus::Rejected)
            ->orderByDesc('date')
            ->orderByDesc('check_in')
            ->orderByDesc('segment')
            ->get()
            ->reject(fn (Attendance $row): bool => ($shown !== null && $shown->is($row)) || $attendanceCheck->isLiveClosable($row))
            ->values();
    }

    /**
     * The open row the released app prompts for ({@see self::unclosedRows()}): the latest one of an earlier
     * day, as before several schedules a day existed, so an earlier session of today never hides a forgotten
     * check-out of a previous day; an earlier session of today only when no earlier day has one. A row whose
     * date already has a pending correction gives way to an older one that has none. Every open row is listed
     * in "unclosed_list".
     *
     * @param  Collection<int, Attendance>  $unclosed
     * @param  Collection<string, int>  $pendingDates
     * @return array{date: string, check_in: string|null, shift: string|null, correction_pending: bool}|null
     */
    private function unclosedPrevious(Collection $unclosed, Collection $pendingDates): ?array
    {
        $earlierDays = $unclosed->filter(fn (Attendance $row): bool => $row->date->lt(today()))->values();
        $candidates = $earlierDays->isNotEmpty() ? $earlierDays : $unclosed;

        if ($candidates->isEmpty()) {
            return null;
        }

        /** @var Attendance $row */
        $row = $candidates->first(fn (Attendance $row): bool => ! $pendingDates->has($row->date->toDateString())) ?? $candidates->first();

        return $this->unclosedEntry($row, $pendingDates);
    }

    /**
     * The dates (Y-m-d, as keys) of the open rows that already have a pending correction.
     *
     * @param  Collection<int, Attendance>  $unclosed
     * @return Collection<string, int>
     */
    private function pendingCorrectionDates(User $user, Collection $unclosed): Collection
    {
        if ($unclosed->isEmpty()) {
            return collect();
        }

        return AttendanceCorrection::query()
            ->where(array_filter(Attendance::workerKeys($user)))
            ->whereDate('date', '>=', $unclosed->last()->date)
            ->whereDate('date', '<=', $unclosed->first()->date)
            ->where('status', 'pending')
            ->pluck('date')
            ->map(fn ($date): string => Carbon::parse($date)->toDateString())
            ->flip();
    }

    /**
     * @param  Collection<string, int>  $pendingDates
     * @return array{date: string, check_in: string|null, shift: string|null, correction_pending: bool}
     */
    private function unclosedEntry(Attendance $row, Collection $pendingDates): array
    {
        return [
            'date' => $row->date->toDateString(),
            'check_in' => $row->check_in?->format('H:i'),
            'shift' => $row->shift?->name,
            'correction_pending' => $pendingDates->has($row->date->toDateString()),
        ];
    }

    /**
     * Whether today's first-segment row of the worker is an approved leave.
     */
    private function isOnApprovedLeaveToday(User $user): bool
    {
        return $this->isApprovedLeaveRow(Attendance::byWorker($user)->whereDate('date', today())->where('segment', 1)->first());
    }

    /**
     * Whether the row is a leave day backed by an approved leave request, the same rule check-in applies.
     *
     * A leave row whose leave was deleted or re-dated no longer blocks check-in.
     */
    private function isApprovedLeaveRow(?Attendance $attendance): bool
    {
        if ($attendance === null || $attendance->check_in !== null || $attendance->status !== AttendanceStatus::Leave) {
            return false;
        }

        $workerKeys = array_filter([
            'employee_id' => $attendance->employee_id,
            'intern_id' => $attendance->intern_id,
            'freelancer_id' => $attendance->freelancer_id,
        ]);

        if ($workerKeys === []) {
            return false;
        }

        $date = $attendance->date->toDateString();

        return LeaveRequest::query()
            ->where($workerKeys)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->exists();
    }

    /**
     * An outlet with the geofence the server checks against, so the app's preview matches it.
     *
     * @return array{id: int, name: string, latitude: float|null, longitude: float|null, radius_meters: int}|null
     */
    private function outletGeofence(?CompanyLocation $outlet): ?array
    {
        if (! $outlet) {
            return null;
        }

        $outlet->loadMissing('company.policy');

        return [
            'id' => (int) $outlet->id,
            'name' => $outlet->name,
            'latitude' => $outlet->latitude !== null ? (float) $outlet->latitude : null,
            'longitude' => $outlet->longitude !== null ? (float) $outlet->longitude : null,
            'radius_meters' => GeofenceService::resolveRadius($outlet->radius_meters, $outlet->company?->policy?->geofence_radius_meters),
        ];
    }

    public function deleteMasterFace(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = $user->employee ?? $user->intern ?? $user->freelancer;

        if (! $profile) {
            return response()->json(['message' => 'Profil pengguna tidak ditemukan.'], 422);
        }

        if (! $profile->master_face_photo) {
            return response()->json(['message' => 'Foto profil belum pernah dipasang.'], 400);
        }

        $profile->update([
            'master_face_photo' => null,
            'master_face_verified_at' => null,
        ]);

        return response()->json([
            'message' => 'Foto profil berhasil dihapus.',
        ]);
    }
}
