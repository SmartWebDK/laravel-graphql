<?php
declare(strict_types = 1);


namespace Folklore\GraphQL;

use Folklore\GraphQL\Registry\TypeRegistry;
use Folklore\GraphQL\Registry\TypeRegistryInterface;
use GraphQL\Validator\DocumentValidator;
use GraphQL\Validator\Rules\DisableIntrospection;
use GraphQL\Validator\Rules\QueryComplexity;
use GraphQL\Validator\Rules\QueryDepth;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
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
     */
    public function boot() : void
    {
        $this->bootPublishes();
        
        $this->bootRouter();
        
        $this->bootViews();
    }
    
    /**
     * Bootstrap router.
     */
    protected function bootRouter() : void
    {
        if ($this->app['config']->get('graphql.routes') && !$this->app->routesAreCached()) {
            $router = $this->getRouter();
            include __DIR__ . '/routes.php';
        }
    }
    
    /**
     * Bootstrap events.
     *
     * @param GraphQL    $graphql
     * @param Dispatcher $dispatcher
     */
    protected function registerEventListeners(GraphQL $graphql, Dispatcher $dispatcher) : void
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
     * @param GraphQL $graphql
     */
    protected function addTypes(GraphQL $graphql) : void
    {
        $types = $this->app['config']->get('graphql.types', []);
        
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
     * @param GraphQL $graphql
     */
    protected function addSchemas(GraphQL $graphql) : void
    {
        $schemas = $this->app['config']->get('graphql.schemas', []);
        
        foreach ($schemas as $name => $schema) {
            $graphql->addSchema($name, $schema);
        }
    }
    
    /**
     * Bootstrap views.
     */
    protected function bootViews() : void
    {
        $config = $this->app['config'];
        
        if ($config->get('graphql.graphiql', true)) {
            $view = $config->get('graphql.graphiql.view', 'graphql::graphiql');
            $composer = $config->get('graphql.graphiql.composer', View\GraphiQLComposer::class);
            $this->app['view']->composer($view, $composer);
        }
    }
    
    /**
     * Configure security from config.
     */
    protected function applySecurityRules() : void
    {
        $maxQueryComplexity = config('graphql.security.query_max_complexity');
        if ($maxQueryComplexity !== null) {
            /** @var QueryComplexity $queryComplexity */
            $queryComplexity = DocumentValidator::getRule('QueryComplexity');
            $queryComplexity->setMaxQueryComplexity($maxQueryComplexity);
        }
        
        $maxQueryDepth = config('graphql.security.query_max_depth');
        if ($maxQueryDepth !== null) {
            /** @var QueryDepth $queryDepth */
            $queryDepth = DocumentValidator::getRule('QueryDepth');
            $queryDepth->setMaxQueryDepth($maxQueryDepth);
        }
        
        $disableIntrospection = config('graphql.security.disable_introspection');
        if ($disableIntrospection === true) {
            /** @var DisableIntrospection $disableIntrospection */
            $disableIntrospection = DocumentValidator::getRule('DisableIntrospection');
            $disableIntrospection->setEnabled(DisableIntrospection::ENABLED);
        }
    }
    
    /**
     * Register any application services.
     *
     * @param Dispatcher $dispatcher
     */
    public function register(Dispatcher $dispatcher) : void
    {
        $this->registerGraphQL($dispatcher);
        
        $this->registerConsole();
    }
    
    /**
     * Register GraphQL facade.
     *
     * @param Dispatcher $dispatcher
     */
    protected function registerGraphQL(Dispatcher $dispatcher) : void
    {
        $this->app->singleton(
            TypeRegistryInterface::class,
            static function () : TypeRegistryInterface {
                return new TypeRegistry();
            }
        );
        
        $this->app->singleton(
            'graphql',
            function (Application $app) use ($dispatcher) {
                $graphql = $app->make(GraphQL::class);
                
                $this->addTypes($graphql);
                
                $this->addSchemas($graphql);
        
                $this->registerEventListeners($graphql, $dispatcher);
                
                $this->applySecurityRules();
                
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
