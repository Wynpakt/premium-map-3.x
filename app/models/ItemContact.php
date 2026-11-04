<?php

class ItemContact extends \Phalcon\Mvc\Model
{
    protected $id;
    protected $item_id;
    protected $collmex_id;
    protected $name;
    protected $department;
    protected $phone;
    protected $phone2;
    protected $email;

    public function setItemId($item_id)
    {
        $this->item_id = $item_id;

        return $this;
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

    public function setDepartment($department)
    {
        $this->department = $department;

        return $this;
    }

    public function setPhone($phone)
    {
        $this->phone = $phone;

        return $this;
    }

    public function setPhone2($phone2)
    {
        $this->phone2 = $phone2;

        return $this;
    }

    public function setEmail($email)
    {
        $this->email = $email;

        return $this;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getDepartment()
    {
        return $this->department;
    }

    public function getPhone()
    {
        return $this->phone;
    }

    public function getPhone2()
    {
        return $this->phone2;
    }

    public function getEmail()
    {
        return $this->email;
    }

    public function getItemId()
    {
        return $this->item_id;
    }

    public function getCollmexId()
    {
        return $this->collmex_id;
    }

    public function columnMap()
    {
        return array(
            'id' => 'id',
            'item_id' => 'item_id',
            'collmex_id' => 'collmex_id',
            'name' => 'name',
            'department' => 'department',
            'phone' => 'phone',
            'phone2' => 'phone2',
            'email' => 'email',
        );
    }
}
