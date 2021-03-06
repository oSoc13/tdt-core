<?php

/**
 * This is the abstract class for a strategy.
 *
 * @package The-Datatank/model/resources
 * @license AGPLv3
 * @author Pieter Colpaert   <pieter@iRail.be>
 * @author Jan Vansteenlandt <jan@iRail.be>
 */

namespace tdt\core\model\resources;

use tdt\core\model\DBQueries;
use tdt\core\model\resources\GenericResource;
use tdt\core\model\ResourcesModel;
use tdt\exceptions\TDTException;
use RedBean_Facade as R;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use tdt\core\utility\Config;

abstract class AResourceStrategy {

    protected static $DEFAULT_PAGE_SIZE = 500;
    protected $rest_params = array();
    protected $link_referrals = array("last","previous","next");

    /**
     * This functions contains the businesslogic of a read method (non paged reading)
     * @return \stdClass object representing the result of the businesslogic.
     */
    abstract public function read(&$configObject, $package, $resource);

    /**
     * Delete all extra information on the server about this resource when it gets deleted
     */
    public function onDelete($package, $resource) {
        // get the name of the class (=strategy)
        $strat = $this->getClassName();
        $resource_table = (string) GenericResource::$TABLE_PREAMBLE . $strat;
        return DBQueries::deleteStrategy($package,$resource,$resource_table);
    }

    /*
     * Returns the class name without the namespace
     */

    protected function getClassName() {
        // get the name of the class ( = strategyname)
        // but without the namespace!!
        $class = explode('\\', get_class($this));
        $classname = end($class);
        return strtolower($classname);
    }

    /**
     * When a strategy is added, execute this piece of code.
     */
    public function onAdd($package_id, $gen_resource_id) {
        if ($this->isValid($package_id, $gen_resource_id)) {
            // get the name of the class ( = strategyname)
            $strat = $this->getClassName();
            $resource = R::dispense(GenericResource::$TABLE_PREAMBLE . $strat);
            $resource->gen_resource_id = $gen_resource_id;

            // for every parameter that has been passed for the creation of the strategy, make a datamember
            $createParams = array_keys($this->documentCreateParameters());

            foreach ($createParams as $createParam) {
                // dont add the columns parameter, this is a separate parameter that's been stored into another table
                // every parameter that requires separate tables, apart from the autogenerated one
                // must be included in the if else structure.
                if ($createParam != "columns") {
                    if (!isset($this->$createParam)) {
                        $resource->$createParam = "";
                    } else {
                        $resource->$createParam = $this->$createParam;
                    }
                }
            }
            return R::store($resource);
        }else{
            $exception_config = array();
            $exception_config["log_dir"] = Config::get("general", "logging", "path");
            $exception_config["url"] = Config::get("general", "hostname") . Config::get("general", "subdir") . "error";
            throw new TDTException(452, array("Something went wrong during the validation of the generic resource."), $exception_config);
        }
    }

    public function onUpdate($package, $resource) {

    }

    public function setParameter($key, $value) {
        $this->$key = $value;
    }

    public function setRestParameters($rest_params = array()){
        $this->rest_params = $rest_params;
    }

    /**
     * Gets all the required parameters to add a resource with this strategy
     * @return array with the required parameters to add a resource with this strategy
     */
    public function documentCreateRequiredParameters() {
        return array();
    }

    public function documentReadRequiredParameters() {
        return array();
    }

    public function documentUpdateRequiredParameters() {
        return array();
    }

    public function documentCreateParameters() {
        return array();
    }

    public function documentReadParameters() {
        return array();
    }

    public function documentUpdateParameters() {
        return array();
    }

    /**
     *  This function gets the fields in a resource
     * @param string $package
     * @param string $resource
     * @return array with column names mapped onto their aliases
     */
    public function getFields($package, $resource) {
        return array();
    }

    /**
     * This functions performs the validation of the addition of a strategy
     * It does not contain any arguments, because the parameters are datamembers of the object
     * Default: true, if you want your own validation, overwrite it in your strategy.
     * NOTE: this validation is not only meant to validate parameters, but also your dataresource.
     * For example in a CSV file, we also check for the column headers, and we store them in the published columns table
     * This table is linked to a generic resource, thus can be accessed by any strategy!
     * IMPORTANT !!: throw an exception when you want your personal error message for the validation.
     */
    protected function isValid($package_id, $gen_resource_id) {
        return true;
    }

    /**
     * Throws an exception with a message, and prohibits the resource to be added
     * This function should only be used when validating a resource!!!
     */
    protected function throwException($package_id, $gen_resource_id, $message) {

        $log = new Logger('AResourceStrategy');
        $log->pushHandler(new StreamHandler(Config::get("general", "logging", "path") . "/log_" . date('Y-m-d') . ".txt", Logger::ERROR));
        $log->addError($message);

        try{
            $resource_id = DBQueries::getAssociatedResourceId($gen_resource_id);
            $package = DBQueries::getPackageById($package_id);
            $resource = DBQueries::getResourceById($resource_id);
            ResourcesModel::getInstance(Config::getConfigArray())->deleteResource($package, $resource, array());
        }catch(TDTException $ex){

        }

        $exception_config = array();
        $exception_config["log_dir"] = Config::get("general", "logging", "path");
        $exception_config["url"] = Config::get("general", "hostname") . Config::get("general", "subdir") . "error";
        throw new TDTException(452, array($message), $exception_config);
    }

    /**
     * setLinkHeader sets a Link header with next, previous
     * @param int $limit  The limitation of the amount of objects to return
     * @param int $offset The offset from where to begin to return objects (default = 0)
     */
    protected function setLinkHeader($page,$page_size,$referral = "next"){

        /**
         * Process the correct referral options(next | previous)
         */
        if(!in_array($referral,$this->link_referrals)){
           $log = new Logger('AResourceStrategy');
           $log->pushHandler(new StreamHandler(Config::get("general", "logging", "path") . "/log_" . date('Y-m-d') . ".txt", Logger::ERROR));
           $log->addError("No correct referral has been found, options are 'next' or 'previous', the referral given was: $referral");
       }

        /**
         * Check if the Link header has already been set, with a next relationship for example.
         * If so we have to append the Link header instead of hard setting it
         */
        $link_header_set = false;
        foreach(headers_list() as $header){
            if(substr($header,0,4) == "Link"){
                $header.=", ". Config::get("general","hostname") . Config::get("general","subdir") . $this->package . "/" . $this->resource . ".about?page="
                . $page . "&page_size=" . $page_size . ";rel=" . $referral;
                header($header);
                $link_header_set = true;
            }
        }

        if(!$link_header_set){
            header("Link: ". Config::get("general","hostname") . Config::get("general","subdir") . $this->package . "/" . $this->resource . ".about?page="
                . $page . "&page_size=" . $page_size . ";rel=" . $referral);
        }
    }

    /**
     * Calculate the limit and offset based on the request string parameters.
     */
    protected function calculateLimitAndOffset(){

        if(empty($this->limit) && empty($this->offset)){

            if(empty($this->page)){
                $this->page = 1;
            }

            if(empty($this->page_size)){
                $this->page_size = AResourceStrategy::$DEFAULT_PAGE_SIZE;
            }

            if($this->page == -1){ // Return all of the result-set == no paging.
                $this->limit = 2147483647; // max int on 32-bit machines
                $this->offset= 0;
                $this->page_size = 2147483647;
                $this->page = 1;
            }else{
                $this->offset = ($this->page -1)*$this->page_size;
                $this->limit = $this->page_size;
            }
        }else{

            if(empty($this->limit)){
                $this->limit = AResourceStrategy::$DEFAULT_PAGE_SIZE;
            }

            if(empty($this->offset)){
                $this->offset = 0;
            }

            if($this->limit == -1){
                $this->limit = 2147483647;
                $this->page = 1;
                $this->page_size = 2147483647;
                $this->offset = 0;
            }else{
                // calculate the page and size from limit and offset as good as possible
                // meaning that if offset<limit, indicates a non equal division of pages
                // it will try to restore that equal division of paging
                // i.e. offset = 2, limit = 20 -> indicates that page 1 exists of 2 rows, page 2 of 20 rows, page 3 min. 20 rows.
                // paging should be (x=size) x, x, x, y < x EOF
                $page = $this->offset/$this->limit;
                $page = round($page,0,PHP_ROUND_HALF_DOWN);
                if($page==0){
                    $page = 1;
                }
                $this->page = $page;
                $this->page_size = $this->limit ;
            }
        }
    }
}