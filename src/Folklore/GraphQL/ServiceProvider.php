<?php
declare(strict_types = 1);


namespace Folklore\GraphQL;

use Folklore\GraphQL\Registry\TypeRegistry;
use Folklore\GraphQL\Registry\TypeRegistryInterface;
use GraphQL\Validator\DocumentValidator;
use GraphQL\Validator\Rules\DisableIntrospection;
use GraphQL\Validator\Rules\QueryComplexity;
use GraphQL\Validator\Rules\QueryDepth;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Foundation\Application as LaravelApplication;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

/**
 * Laravel-compatible service provider.
 *
 * @author Nicolai Agersbæk <na@zitcom.dk>
 */
class ServiceProvider extends BaseServiceProvider
{
    
    /**
     * @return mixed
     */
    protected function getRouter()
    {
        return $this->app['router'];
    }
    
    /**
     * Bootstrap any application services.
     *
     * @param Repository $config
     * @param Factory    $viewFactory
     */
    public function boot(Repository $config, Factory $viewFactory) : void
    {
        $this->bootPublishes();
    
        $this->bootRouter($config);
    
        $this->bootViews($config, $viewFactory);
    }
    
    /**
     * Bootstrap router.
     *
     * @param Repository $config
     */
    protected function bootRouter(Repository $config) : void
    {
        /** @var LaravelApplication $app */
        $app = $this->app;
    
        if ($config->get('graphql.routes') && !$app->routesAreCached()) {
            $router = $this->getRouter();
            include __DIR__ . '/routes.php';
        }
    }
    
    /**
     * Bootstrap events.
     *
     * @param Dispatcher $dispatcher
     * @param GraphQL    $graphql
     */
    private function registerEventListeners(Dispatcher $dispatcher, GraphQL $graphql) : void
    {
        // Update the schema route pattern when schema is added
        $dispatcher->listen(
            Events\SchemaAdded::class,
            function () use ($graphql) {
                $router = $this->getRouter();
                if (method_exists($router, 'pattern')) {
                    $schemaNames = array_keys($graphql->getSchemas());
                    $router->pattern('graphql_schema', '(' . implode('|', $schemaNames) . ')');
                }
            }
        );
    }
    
    /**
     * Bootstrap publishes.
     */
    protected function bootPublishes() : void
    {
        $configPath = __DIR__ . '/../../config';
        $viewsPath = __DIR__ . '/../../resources/views';
        
        $this->mergeConfigFrom($configPath . '/config.php', 'graphql');
        
        $this->loadViewsFrom($viewsPath, 'graphql');
        
        $this->publishes(
            [
                $configPath . '/config.php' => config_path('graphql.php'),
            ],
            'config'
        );
        
        $this->publishes(
            [
                $viewsPath => base_path('resources/views/vendor/graphql'),
            ],
            'views'
        );
    }
    
    /**
     * Add types from config
     *
     * @param Repository $config
     * @param GraphQL    $graphql
     */
    private function addTypes(Repository $config, GraphQL $graphql) : void
    {
        $types = $config->get('graphql.types', []);
        
        foreach ($types as $name => $type) {
            $graphql->addType(
                $type,
                is_numeric($name)
                    ? null
                    : $name
            );
        }
    }
    
    /**
     * Add schemas from config
     *
     * @param Repository $config
     * @param GraphQL    $graphql
     */
    private function addSchemas(Repository $config, GraphQL $graphql) : void
    {
        $schemas = $config->get('graphql.schemas', []);
        
        foreach ($schemas as $name => $schema) {
            $graphql->addSchema($name, $schema);
        }
    }
    
    /**
     * Bootstrap views.
     *
     * @param Repository $config
     * @param Factory    $viewFactory
     */
    private function bootViews(Repository $config, Factory $viewFactory) : void
    {
        if ($config->get('graphql.graphiql', true)) {
            $view = $config->get('graphql.graphiql.view', 'graphql::graphiql');
            $composer = $config->get('graphql.graphiql.composer', View\GraphiQLComposer::class);
            $viewFactory->composer($view, $composer);
        }
    }
    
    /**
     * Configure security from config.
     *
     * @param Repository $config
     */
    private function applySecurityRules(Repository $config) : void
    {
        $maxQueryComplexity = $config->get('graphql.security.query_max_complexity');
        if ($maxQueryComplexity !== null) {
            /** @var QueryComplexity $queryComplexity */
            $queryComplexity = DocumentValidator::getRule('QueryComplexity');
            $queryComplexity->setMaxQueryComplexity($maxQueryComplexity);
        }
    
        $maxQueryDepth = $config->get('graphql.security.query_max_depth');
        if ($maxQueryDepth !== null) {
            /** @var QueryDepth $queryDepth */
            $queryDepth = DocumentValidator::getRule('QueryDepth');
            $queryDepth->setMaxQueryDepth($maxQueryDepth);
        }
    
        $disableIntrospection = $config->get('graphql.security.disable_introspection');
        if ($disableIntrospection === true) {
            /** @var DisableIntrospection $disableIntrospection */
            $disableIntrospection = DocumentValidator::getRule('DisableIntrospection');
            $disableIntrospection->setEnabled(DisableIntrospection::ENABLED);
        }
    }
    
    /**
     * Register any application services.
     */
    public function register() : void
    {
        /**
         * @var Dispatcher $dispatcher
         * @var Repository $config
         */
        $dispatcher = $this->app->get(Dispatcher::class);
        $config = $this->app->get(Repository::class);
    
        $this->registerGraphQL($config, $dispatcher);
        
        $this->registerConsole();
    }
    
    /**
     * Register GraphQL facade.
     *
     * @param Repository $config
     * @param Dispatcher $dispatcher
     */
    protected function registerGraphQL(Repository $config, Dispatcher $dispatcher) : void
    {
        $this->app->singleton(
            TypeRegistryInterface::class,
            static function () : TypeRegistryInterface {
                return new TypeRegistry();
            }
        );
        
        $this->app->singleton(
            'graphql',
            function (Application $app) use ($config, $dispatcher) {
                $graphql = $app->make(GraphQL::class);
        
                $this->addTypes($config, $graphql);
        
                $this->addSchemas($config, $graphql);
        
                $this->registerEventListeners($dispatcher, $graphql);
        
                $this->applySecurityRules($config);
                
                return $graphql;
            }
        );
    }
    
    /**
     * Register console commands.
     */
    protected function registerConsole() : void
    {
        $this->commands(Console\TypeMakeCommand::class);
        $this->commands(Console\QueryMakeCommand::class);
        $this->commands(Console\MutationMakeCommand::class);
        $this->commands(Console\EnumMakeCommand::class);
        $this->commands(Console\FieldMakeCommand::class);
        $this->commands(Console\InterfaceMakeCommand::class);
        $this->commands(Console\ScalarMakeCommand::class);
    }
    
    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides() : array
    {
        return ['graphql'];
    }
}
