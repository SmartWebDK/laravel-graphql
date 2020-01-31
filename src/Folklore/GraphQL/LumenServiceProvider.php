<?php namespace Folklore\GraphQL;

use Folklore\GraphQL\Console\PublishCommand;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;

class LumenServiceProvider extends ServiceProvider
{
    
    /**
     * Get the active router.
     *
     * @return Router
     */
    protected function getRouter()
    {
        return property_exists($this->app, 'router') ? $this->app->router : $this->app;
    }
    
    /**
     * Bootstrap publishes
     */
    protected function bootPublishes() : void
    {
        $configPath = __DIR__ . '/../../config';
        $viewsPath = __DIR__ . '/../../resources/views';
        $this->mergeConfigFrom($configPath . '/config.php', 'graphql');
        $this->loadViewsFrom($viewsPath, 'graphql');
    }
    
    /**
     * Bootstrap router
     *
     * @param Repository $config
     */
    protected function bootRouter(Repository $config) : void
    {
        if ($this->app['config']->get('graphql.routes')) {
            Routes::register($this->getRouter());
        }
    }
    
    /**
     * Register GraphQL facade.
     *
     * @param Repository $config
     * @param Dispatcher $dispatcher
     */
    protected function registerGraphQL(Repository $config, Dispatcher $dispatcher) : void
    {
        static $registred = false;
        // Check if facades are activated
        if (Facade::getFacadeApplication() == $this->app && !$registred) {
            class_alias(\Folklore\GraphQL\Support\Facades\GraphQL::class, 'GraphQL');
            $registred = true;
        }
        
        parent::registerGraphQL($config, $dispatcher);
    }
    
    /**
     * Register the helper command to publish the config file
     */
    public function registerConsole() : void
    {
        parent::registerConsole();
        
        $this->commands(PublishCommand::class);
    }
}
