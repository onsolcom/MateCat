<?php
use Teams\TeamStruct;

/**
 * Created by PhpStorm.
 * User: fregini
 * Date: 3/11/16
 * Time: 11:00 AM
 */
class FeatureSet {

    private $features = array();

    /**
     * @param array $features
     */
    public function __construct( array $features = array() ) {
        $this->features = $features;

        $this->__loadFromMandatory();
    }

    public function getCodes() {
        return array_values( array_map( function( $feature ) { return $feature->feature_code ; }, $this->features) );
    }

    public function loadFromString( $string ) {
        $feature_codes = FeatureSet::splitString( $string );
        $features = array();

        if ( !empty( $feature_codes ) ) {
            foreach( $feature_codes as $code ) {
                $features [] = new BasicFeatureStruct( array( 'feature_code' => $code ) );
            }
            $this->features = static::merge($this->features, $features);
        }
    }

    /**
     * Features are attached to project via project_metadata.
     *
     * @param Projects_ProjectStruct $project
     */
    public function loadForProject( Projects_ProjectStruct $project ) {
        $this->loadFromString( $project->getMetadataValue( Projects_MetadataDao::FEATURES_KEY ) );
    }

    public function loadProjectDependenciesFromProjectMetadata( $metadata ) {
        $project_dependencies = [];
        $project_dependencies = $this->filter('filterProjectDependencies', $project_dependencies, $metadata );
        $features = [] ;
        foreach( $project_dependencies as $dependency ) {
            $features [] = new BasicFeatureStruct( array( 'feature_code' => $dependency ) );
        }
        $this->features = static::merge( $this->features, $features );
    }

    /**
     *
     * @param $id_customer
     */
    public function loadFromUserEmail( $id_customer ) {
        $features = OwnerFeatures_OwnerFeatureDao::getByIdCustomer( $id_customer );
        $this->features = static::merge( $this->features, $features );
    }

    /**
     * Loads the features starting from a given team.
     *
     * @param TeamStruct $team
     */
    public function loadFromTeam( TeamStruct $team ) {
        $dao = new OwnerFeatures_OwnerFeatureDao() ;
        $features = $dao->getByTeam( $team ) ;
        $this->features = static::merge( $this->features, $features ) ;
    }

    /**
     * Returns the filtered subject variable passed to all enabled features.
     *
     * @param $method
     * @param $filterable
     *
     * @return mixed
     *
     * FIXME: this is not a real filter since the input params are not passed
     * modified in cascade to the next function in the queue.
     * @throws Exceptions_RecordNotFound
     * @throws \Exceptions\ValidationError
     * @internal param $id_customer
     */
    public function filter($method, $filterable) {
        $args = array_slice( func_get_args(), 1);

        foreach( $this->features as $feature ) {
            $name = "Features\\" . $feature->toClassName() ;
            if ( class_exists( $name ) ) {
                $obj = new $name( $feature );

                if ( method_exists( $obj, $method ) ) {
                    array_shift( $args );
                    array_unshift( $args, $filterable );

                    try {
                        $filterable = call_user_func_array( array( $obj, $method ), $args );
                    } catch ( \Exceptions\ValidationError $e ) {
                        throw $e ;
                    } catch ( Exceptions_RecordNotFound $e ) {
                        throw $e ;
                    } catch ( Exception $e ) {
                        Log::doLog("Exception running filter " . $method . ": " . $e->getMessage() );
                    }
                }
            }
        }

        return $filterable ;
    }

    /**
     * @param $method
     */
    public function run( $method ) {
        $args = array_slice( func_get_args(), 1 );

        foreach ( $this->features as $feature ) {
            $this->runOnFeature($method, $feature, $args);
        }
    }

    /**
     * appendDecorators
     *
     * Loads feature specific decorators, if any is found.
     *
     * Also, gives a last chance to plugins to define a custom decorator class to be
     * added to any call.
     *
     * @param $name name of the decorator to activate
     * @param viewController $controller the controller to work on
     * @param PHPTAL $template the PHPTAL view to add properties to
     *
     */
    public function appendDecorators($name, viewController $controller, PHPTAL $template) {
        foreach( $this->features as $feature ) {

            $baseClass = "Features\\" . $feature->toClassName()  ;

            $cls =  "$baseClass\\Decorator\\$name" ;

            // XXX: keep this log line because due to a bug in Log class
            // if this line is missing it won't log load errors.
            Log::doLog('loading Decorator ' . $cls );

            if ( class_exists( $cls ) ) {
                $obj = new $cls( $controller, $template ) ;
                $obj->decorate();
            }
        }
    }

    /**
     * Returns an array of feature object instances, merging two input array,
     * ensuring no duplicates are present.
     *
     * @param $left
     * @param $right
     *
     * @return array
     */
    public static function merge( $left, $right ) {
        $returnable = array();

        foreach( $left as $feature ) {
            $returnable[ $feature->feature_code ] = $feature ;
        }

        foreach( $right as $feature ) {
            if ( !isset( $returnable[ $feature->feature_code ] ) ) {
                $returnable[ $feature->feature_code ] = $feature ;
            }
        }

        return $returnable ;
    }

    public static function splitString( $string ) {
        return explode(',', $string);
    }

    /**
     * Loads plugins into the featureset from the list of mandatory plugins.
     */
    private function __loadFromMandatory() {
        $features = [] ;

        if ( INIT::$DQF_ENABLED ) {
            $features[] = new BasicFeatureStruct(['feature_code' => Features::DQF ]) ;
        }

        if ( !empty( INIT::$MANDATORY_PLUGINS ) )  {
            foreach( INIT::$MANDATORY_PLUGINS as $plugin) {
                $features[] = new BasicFeatureStruct(['feature_code' => $plugin ] );
            }
        }

        $this->features = static::merge($this->features, $features);
    }

    /**
     * Runs a command on a single feautre
     *
     * @param $method
     * @param $feature
     * @param $args
     */
    private function runOnFeature($method, BasicFeatureStruct $feature, $args) {
        $name = self::getClassName( $feature->feature_code );

        if ( $name ) {
            $obj = new $name($feature);

            if (method_exists($obj, $method)) {
                call_user_func_array(array($obj, $method), $args);
            }
        }
    }

    public static function getClassName( $code ) {
        $className = '\Features\\' . Utils::underscoreToCamelCase( $code );
        if ( class_exists( $className ) ) {
            return $className;
        }
        else {
            return false ;
        }
    }

}