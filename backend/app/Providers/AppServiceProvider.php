<?php

namespace App\Providers;

use App\Http\Responses\AuthError;
use App\Models\User;
use App\OpenApi\AuthDocumentTransformer;
use App\Support\TestDatabaseSafety;
use Dedoc\Scramble\Scramble;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $username = $request->input('username');
            $normalized = is_string($username) ? Str::lower(trim($username)) : '';

            return Limit::perMinute(5)
                ->by(hash('sha256', $normalized).'|'.$request->ip())
                ->response(fn (Request $request, array $headers) => AuthError::TooManyRequests->response($headers));
        });

        Gate::define('viewApiDocs', fn (?User $user = null) => config('scramble.enabled') ?? app()->environment(['local', 'testing']));
        Scramble::configure()->withDocumentTransformers(AuthDocumentTransformer::class);

        Event::listen(CommandStarting::class, function (CommandStarting $event) {
            if (app()->environment('testing') && Str::is(['migrate*', 'db:wipe', 'db:seed', 'schema:dump'], $event->command ?? '')) {
                if ($event->input->hasParameterOption('--database')) {
                    throw new \RuntimeException('Testing database overrides are forbidden; configure the confirmed default mysql connection.');
                }
                TestDatabaseSafety::assertAvailable(app());
            }
        });
    }
}
