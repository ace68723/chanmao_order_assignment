<?php

namespace App\Providers\MapService\GoogleMapFinalBytes\Response;

class Row
{
    private $elements;

    public function __construct($elements)
    {
        $this->elements = $elements;
    }

    public function getElements() : array
    {
        return $this->elements;
    }
}
