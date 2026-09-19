<?php

namespace App\Console\Commands;

use App\Enums\AppRole;
use App\Models\Depot;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Creates or updates a panel user with roles and plant access.
 *
 * Planners in every state need an account in the one app, so this is the supported way to add
 * them rather than a one-off tinker session. The password is always typed by the person running
 * the command and never passed as an argument, so it stays out of shell history.
 */
class MakeUserCommand extends Command
{
    protected $signature = 'rcpa:make-user
        {--email= : Sign-in address.}
        {--name= : Display name.}
        {--role=* : App roles, e.g. Planner.Admin. Repeatable.}
        {--depot=* : Plant codes this user may see, e.g. MEL. Repeatable. Omit for all.}';

    protected $description = 'Create or update a user for the admin panel';

    public function handle(): int
    {
        $email = $this->option('email') ?: $this->ask('Email address');
        $name = $this->option('name') ?: $this->ask('Name');

        $roles = $this->option('role') ?: $this->choice(
            'Roles',
            array_map(fn (AppRole $role): string => $role->value, AppRole::cases()),
            AppRole::PlannerAdmin->value,
            multiple: true,
        );

        foreach ($roles as $role) {
            if (AppRole::tryFrom($role) === null) {
                $this->components->error("Unknown role [{$role}]. Valid roles: ".implode(', ', array_map(
                    fn (AppRole $case): string => $case->value,
                    AppRole::cases(),
                )));

                return self::INVALID;
            }
        }

        $password = $this->secret('Password (input hidden)');

        $validator = Validator::make(
            ['email' => $email, 'name' => $name, 'password' => $password],
            [
                'email' => ['required', 'email'],
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', Password::min(12)],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::INVALID;
        }

        if ($password !== $this->secret('Confirm password')) {
            $this->components->error('Passwords do not match.');

            return self::INVALID;
        }

        // Assigned directly rather than mass-assigned: the User model limits fillable to name,
        // email and password, so app_roles would be dropped without a word and the account
        // would sign in with no access to anything.
        $user = User::query()->firstOrNew(['email' => $email]);
        $user->name = $name;
        // The model casts password to 'hashed', so hashing here would store a hash of a hash.
        $user->password = $password;
        $user->app_roles = array_values(array_unique($roles));
        $user->email_verified_at ??= now();
        $user->save();

        $this->attachDepots($user);

        $this->newLine();
        $this->components->info("{$user->email} is ready. Sign in at ".config('app.url').'/admin');
        $this->components->twoColumnDetail('Roles', implode(', ', $user->app_roles ?? []));
        $this->components->twoColumnDetail(
            'Plants',
            $user->depots()->exists()
                ? $user->depots()->pluck('code')->implode(', ')
                : 'All (no depot restriction)',
        );

        return self::SUCCESS;
    }

    private function attachDepots(User $user): void
    {
        $codes = $this->option('depot');

        if ($codes === []) {
            return;
        }

        $depots = Depot::query()->whereIn('code', $codes)->get();
        $missing = array_diff($codes, $depots->pluck('code')->all());

        if ($missing !== []) {
            $this->components->warn('Unknown plant codes ignored: '.implode(', ', $missing));
        }

        $user->depots()->sync($depots->pluck('id'));
    }
}
