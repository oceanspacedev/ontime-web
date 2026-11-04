<?php

namespace App\Services\Core;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class CoreUserSynchronizer
{
    public function sync(array $coreUserPayload): User
    {
        $user = User::query()->updateOrCreate(
            ['email' => $coreUserPayload['email']],
            [
                'name' => $coreUserPayload['name'],
                'password' => User::query()
                    ->where('email', $coreUserPayload['email'])
                    ->value('password') ?? bcrypt(Str::password(32)),
            ],
        );

        if (! $user->employee()->exists()) {
            // The link lives on employees.user_id. Only claim an employee that is not linked yet,
            // and keep the email / phone match grouped so it cannot widen the query.
            $employee = Employee::query()
                ->whereNull('user_id')
                ->where(function ($query) use ($coreUserPayload) {
                    $query->where('email', $coreUserPayload['email']);

                    if (! empty($coreUserPayload['phone'])) {
                        $query->orWhere('phone', $coreUserPayload['phone']);
                    }
                })
                ->first();

            $employee?->update(['user_id' => $user->id]);
        }

        // The panel requires a role; a linked employee without one starts as Employee.
        if ($user->roles()->doesntExist() && $user->employee()->exists() && Role::where('name', 'Employee')->exists()) {
            $user->assignRole('Employee');
        }

        return $user;
    }
}
