<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\HasPresensiActions;
use App\Services\Attendance\AttendanceRuleException;
use App\Support\SelfieUploadToken;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;
use UnitEnum;

class AbsenHariIni extends Page
{
    use HasPresensiActions;

    protected static ?string $title = 'Presensi Mandiri';

    protected static ?string $navigationLabel = 'Presensi Mandiri';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-camera';

    protected static string|UnitEnum|null $navigationGroup = 'Presensi & Pengajuan';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.absen-hari-ini';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user || $user->hasRole('Superadmin')) {
            return false;
        }

        return (bool) ($user->employee || $user->intern || $user->freelancer);
    }

    protected function getHeaderActions(): array
    {
        $policy = $this->companyPolicy;

        if (! in_array($policy?->selfieMode(), ['face', 'liveness'], true)) {
            return [];
        }

        $profile = $this->getLinkedProfile();

        if (! empty($profile?->master_face_photo)) {
            return [];
        }

        return [
            $this->updateMasterFaceAction(),
        ];
    }

    public function updateMasterFaceAction(): Action
    {
        $profile = $this->getLinkedProfile();
        $hasMasterPhoto = ! empty($profile?->master_face_photo);

        return Action::make('updateMasterFace')
            ->label($hasMasterPhoto ? 'Ubah Foto Master' : 'Foto Master Wajah')
            ->icon($hasMasterPhoto ? 'heroicon-o-pencil-square' : 'heroicon-o-user-circle')
            ->color($hasMasterPhoto ? 'gray' : 'info')
            ->modalHeading($hasMasterPhoto ? 'Ubah Foto Master Wajah' : 'Pendaftaran Foto Master Wajah')
            ->modalDescription('Unggah foto dari galeri/penyimpanan atau ambil foto via kamera langsung sebagai referensi biometrik Anda.')
            ->mountUsing(function ($form) {
                $profile = $this->getLinkedProfile();
                $photo = $profile?->master_face_photo;
                if ($photo) {
                    $disk = config('filesystems.default');
                    if (Storage::disk($disk)->exists($photo) || Storage::disk('public')->exists($photo)) {
                        $form->fill([
                            'master_face_photo' => $photo,
                        ]);
                    } else {
                        $form->fill([
                            'master_face_photo' => null,
                        ]);
                    }
                }
            })
            ->schema([
                ViewField::make('master_face_photo')
                    ->label('Foto Master Wajah')
                    ->view('filament.components.master-face-capture')
                    ->required()
                    ->validationMessages([
                        'required' => 'Foto Master Wajah wajib diunggah atau diambil via kamera.',
                    ]),
            ])
            ->action(function (array $data): void {
                $profile = $this->getLinkedProfile();

                if (! $profile) {
                    Notification::make()
                        ->title('Profil Tidak Ditemukan')
                        ->body('Akun Anda belum terhubung dengan data Karyawan / Magang / Freelancer.')
                        ->danger()
                        ->send();

                    return;
                }

                $submitted = (string) ($data['master_face_photo'] ?? '');

                // The form keeps the stored path when the photo is unchanged; a new photo arrives as an
                // upload token, so nobody can point their master face at another stored file.
                if ($submitted !== $profile->master_face_photo) {
                    try {
                        $masterPath = SelfieUploadToken::resolve($submitted, auth()->user());
                    } catch (AttendanceRuleException $exception) {
                        Notification::make()
                            ->title('Foto Master Wajah Gagal Disimpan')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    $profile->update([
                        'master_face_photo' => $masterPath,
                        'master_face_verified_at' => now(),
                    ]);
                }

                Notification::make()
                    ->title('Foto Master Wajah Berhasil Disimpan!')
                    ->body('Foto master biometrik Anda telah terdaftar dan diperbarui.')
                    ->success()
                    ->send();
            });
    }
}
