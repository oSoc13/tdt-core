<?php

namespace tdt\core\universalfilter\interpreter\executers\implementations;

use tdt\core\universalfilter\data\UniversalFilterTableContent;
use tdt\core\universalfilter\interpreter\executers\implementations\AggregatorFunctionExecuter;

class AverageAggregatorExecuter extends AggregatorFunctionExecuter {

    public function calculateValue(UniversalFilterTableContent $column, $columnId) {
        $data = $this->convertColumnToArray($column, $columnId);
        $sum = array_sum($data);
        $count = count($data);
        if ($count == 0) {
            return 0;
        }
        return $sum / $count;
    }

    public function keepFullInfo() {
        return false;
    }

    public function getName($name) {
        return "avg_" . $name;
    }

    public function errorIfNoItems() {
        return false;
    }

}



