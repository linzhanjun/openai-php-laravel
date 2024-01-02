<?php

declare(strict_types=1);

namespace OpenAI\Laravel;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use OpenAI;
use OpenAI\Client;
use OpenAI\Contracts\ClientContract;
use OpenAI\Laravel\Commands\InstallCommand;
use OpenAI\Laravel\Exceptions\ApiKeyIsMissing;

/**
 * @internal
 */
final class ServiceProvider extends BaseServiceProvider implements DeferrableProvider
{
    const OPEN_AI = 'open_ai';
    const AZURE_OPEN_AI = 'azure_open_ai';

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ClientContract::class, static function () {
            $config = config('openai');
            $config = self::getConfig($config['default'] ?? '');
            if (!$config) {
                throw ApiKeyIsMissing::create();
            }
            $apiKey = $config['api_key'] ?? '';
            $organization = $config['organization'] ?? null;

            // Azure OpenAI
            if ($config['driver'] == self::AZURE_OPEN_AI) {
                return AzureOpenai::instance($config);
            }

            if (! is_string($apiKey) || ($organization !== null && ! is_string($organization))) {
                throw ApiKeyIsMissing::create();
            }

            // OpenAI
            return OpenAI::factory()
                ->withApiKey($apiKey)
                ->withOrganization($organization)
                ->withHttpHeader('OpenAI-Beta', 'assistants=v1')
                ->withHttpClient(new \GuzzleHttp\Client(['timeout' => config('openai.request_timeout', 30)]))
                ->make();
        });

        $this->app->alias(ClientContract::class, 'openai');
        $this->app->alias(ClientContract::class, Client::class);
    }

    /**
     * @description get default config
     * @param $default
     * @return array|mixed|string
     */
    protected static function getConfig($default = '')
    {
        $config = config('openai');
        if (isset($config['polling']) && $config['polling']) {
            $driver_config = [];
            foreach ($config as $key => $value) {
                if (isset($value['driver']) && $default == $value['driver']) {
                    $driver_config[] = $value;
                }
            }
            return self::polling($driver_config);
        }
        return $config[$default] ?? '';
    }

    /**
     * @description polling configs
     * @param $config
     * @return array
     */
    protected static function polling($config)
    {
        $current_config = [];
        //拆分token轮询;
        if (count($config) > 1) {
            $path = storage_path('app/openai_token/' . md5(json_encode($config)));

            try {
                $token_number = file_get_contents($path);
            } catch (\Exception $e) {
                $token_number = 0;
            }
            //根据$token_number切换数组
            file_put_contents($path, ($token_number + 1));
            $current = $token_number % count($config);
            $current_config = $config[$current] ?? $config[0];
        }

        return $current_config;
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/openai.php' => config_path('openai.php'),
            ]);

            $this->commands([
                InstallCommand::class,
            ]);
        }
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array<int, string>
     */
    public function provides(): array
    {
        return [
            Client::class,
            ClientContract::class,
            'openai',
        ];
    }
}
