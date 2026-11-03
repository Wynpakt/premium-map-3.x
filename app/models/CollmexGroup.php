<?php

class CollmexGroup extends \Phalcon\Mvc\Model
{
    protected $collmex_id;
    protected $name;

    public function initialize()
    {
        $this->setSource('address_group');
    }

    public function setCollmexId($collmex_id)
    {
        $this->collmex_id = $collmex_id;

        return $this;
    }

    public function setName($name)
    {
        $this->name = $name;

        return $this;
    }

    public function getCollmexId()
    {
        return $this->collmex_id;
    }

    public function getName()
    {
        return $this->name;
    }

    public function columnMap()
    {
        return array(
            'collmex_id' => 'collmex_id',
            'name' => 'name',
        );
    }
}
