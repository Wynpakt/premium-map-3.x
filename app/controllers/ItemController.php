<?php

class ItemController extends ControllerBase
{

    public function getAllAction() {
        $items = Item::find();
        $cleaned = [];
        foreach($items as $item) {
            if ($this->rowIsHidden($item->getCollmexAddressGroups())) {
                continue;
            }
            $cleaned_item = array();

            $products = array();
            foreach ($item->products as $p){
                $products[] = $p->name;
            }
            if(count($products) == 0 && $this->requiresOfferAndProduct()) {
                continue;
            }

            $offertypes = array();
                foreach ($item->offertypes as $ot) {
                $offertypes[] = $ot->name;
            }

            $street = $item->street;
            if(count($item->offertypes) == 1 && $item->offertypes[0]->id == 3) {
                $street = '';
            }

            $contact = array();
            if($item->email) {
                $contact[] = $item->email;
            }

            $cleaned[] = [
                'id' => (int)$item->id,
                'name' => $item->name.'',
                'street' => $street.'',
                'products' => $products,
                'offertypes' => $offertypes,
                'city' => $item->city.'',
                'zip' => $item->zip.'',
                'contact' => $contact,
                'web' => $item->web.'',
            ];

        }
        return $this->jsonResponse($cleaned);
    }

    public function apiSearchAction() {
        $string = $this->dispatcher->getParam('string');
        $string = is_string($string) ? trim($string, " \t\n\r/") : '';
        if ($string === '') {
            return $this->jsonResponse(array('items' => array(), 'search' => ''));
        }

        $item = new Item();
        $result = $item->getReadConnection()->query(
            SearchQuery::sql(),
            array('q' => SearchQuery::like($string))
        );
        $items = new \Phalcon\Mvc\Model\Resultset\Simple(null, $item, $result);

        $out = array();
        foreach ($items as $itemRow) {
            if ($this->rowIsHidden($itemRow->getCollmexAddressGroups())) {
                continue;
            }
            if ($this->requiresOfferAndProduct()
                && (count($itemRow->offertypes) == 0 || count($itemRow->products) == 0)) {
                continue;
            }

            $street = $itemRow->street;
            $offertypes = array();
            foreach ($itemRow->offertypes as $ot) {
                $offertypes[] = $ot->id;
            }
            if (count($itemRow->offertypes) == 1 && $itemRow->offertypes[0]->id == 3) {
                $street = '';
            }
            $out[] = array(
                'id' => (int) $itemRow->id,
                'name' => $itemRow->name . '',
                'street' => $street . '',
                'city' => $itemRow->city . '',
                'zip' => $itemRow->zip . '',
                'web' => $itemRow->web . '',
                'email' => $itemRow->email . '',
                'uri' => $itemRow->id,
                'lat' => $itemRow->lat,
                'lng' => $itemRow->lng,
                'offertypes' => $offertypes
            );
        }

        return $this->jsonResponse(array(
            'items' => $out,
            'search' => $string
        ));
    }

    /**
     * get list of marker with geo location
     * @return json
     */
    public function apiListAction() {

        if (ListQuery::rejected($_GET)) {
            return $this->jsonResponse([]);
        }

        if (MapAudience::isInternal($this->audience())) {
            return $this->internalList();
        }

        // defualt list all if no type defined
        $options = [1=>true,2=>true,3=>true,4=>true];

        if(isset($_GET['types'])) {
            $options = [];
            // predefined offertypes look at UpdateTask.php...
            $types = [
                'laeden' => 1,
                'haendler' => 2,
                'sprecher' => 3,
                'webshop' => 4
            ];

            $countries = array("DE", "AT", "CH");
            if(isset($_GET['countries'])) {
                $countries = $_GET['countries'];
            }

            $products = array("cola", "bier", "frohlunder", "muntermate");
            if(isset($_GET['products'])) {
                $products = $_GET['products'];
            }

            foreach ($_GET['types'] as $type) {
                if(isset($types[$type])) {
                    $options[(int)$types[$type]] = true;
                }
            }

            $items = Item::listMarker($countries, $products);
            // return var_dump($items);

            $out = [];
            foreach ($items as $r) {
                if (MarkerQuery::isHidden($r->getCollmexAddressGroups())) {
                    continue;
                }

                if(!isset($out[(int)$r->id])) {
                    $out[(int)$r->id] = [(int)$r->id,[floatval($r->lat),floatval($r->lng)],[]];
                }
                if(!in_array((int)$r->offertype, $out[(int)$r->id][2])) {
                    $out[(int)$r->id][2][] = (int)$r->offertype;
                }
            }

            /*
             * Apply filter for offertypes
             */
            $out2 = [];
            foreach ($out as $r) {                
                $check = false;

                foreach ($r[2] as $offertype) {
                    if(isset($options[$offertype])) {
                        $check = true;
                    }
                }
                if($check) {
                    $out2[] = $r;
                }


            }
            return $this->jsonResponse(array_values($out2));

        }

        // if no types defined
        return $this->jsonResponse([]);
    }

    /**
     * Internal pins include addresses without a role or a product, and groups 16 and 93.
     * A fourth list entry is true when the address is a former customer (group 16).
     */
    private function internalList()
    {
        $catalog = isset($_GET['groups']) ? $this->addressGroupIds() : array();
        $sql = InternalFilter::sql($_GET, $catalog);
        if ($sql === null) {
            return $this->jsonResponse([]);
        }

        $out = array();
        foreach (Item::listBySql($sql) as $row) {
            $id = (int) $row->id;
            if (!isset($out[$id])) {
                $out[$id] = array(
                    $id,
                    array(floatval($row->lat), floatval($row->lng)),
                    array(),
                    AddressGroup::contains($row->getCollmexAddressGroups(), AddressGroup::FORMER),
                );
            }
            if ($row->offertype === null || $row->offertype === '') {
                continue;
            }
            $offerType = (int) $row->offertype;
            if (!in_array($offerType, $out[$id][2], true)) {
                $out[$id][2][] = $offerType;
            }
        }

        return $this->jsonResponse(array_values($out));
    }

    /**
     * Imported Collmex group names for the internal filter.
     * The public map gets an empty list.
     */
    public function apiGroupsAction()
    {
        if (!MapAudience::isInternal($this->audience())) {
            return $this->jsonResponse(array());
        }

        return $this->jsonResponse(AddressGroup::options($this->addressGroupRows()));
    }

    /**
     * @return array<int, string>
     */
    private function addressGroupIds()
    {
        $ids = array();
        foreach ($this->addressGroupRows() as $row) {
            $ids[] = $row['id'];
        }

        return $ids;
    }

    /**
     * @return array<int, array{id: string, name: string}>
     */
    private function addressGroupRows()
    {
        $rows = array();
        $groups = CollmexGroup::find();
        if (!$groups) {
            return $rows;
        }
        foreach ($groups as $group) {
            $rows[] = array(
                'id' => (string) $group->getCollmexId(),
                'name' => (string) $group->getName(),
            );
        }

        return $rows;
    }

    private function audience()
    {
        return MapAudience::fromConfig($this->config);
    }

    private function rowIsHidden($groups)
    {
        return MarkerQuery::isHiddenFor($this->audience(), $groups);
    }

    private function requiresOfferAndProduct()
    {
        return MapAudience::requiresOfferAndProduct($this->audience());
    }
    
    /**
     * gets one marker item with details
     * 
     * @return json
     */
    public function apiGetAction() {
        
        if($id = (int)$this->dispatcher->getParam('id')) {
            if($item = Item::findFirst($id)) {
                if ($this->rowIsHidden($item->getCollmexAddressGroups())) {
                    return $this->jsonResponseError();
                }

                $products = [];
                
                $out = [];

                foreach ($item->products as $p){
                    $products[] = $p->name;
                }

                $offertypes = [];
                $offerTypeIds = [];
                foreach ($item->offertypes as $ot) {
                    $offertypes[] = $ot->name;
                    $offerTypeIds[] = (int) $ot->id;
                }

                $contacts = array();
                $groupNames = array();
                if (MapAudience::isInternal($this->audience())) {
                    $contactRows = ItemContact::find('item_id = ' . (int) $item->getId());
                    if ($contactRows) {
                        foreach ($contactRows as $contact) {
                            $contacts[] = array(
                                'name' => $contact->getName() . '',
                                'department' => $contact->getDepartment() . '',
                                'phone' => $contact->getPhone() . '',
                                'phone2' => $contact->getPhone2() . '',
                                'email' => $contact->getEmail() . '',
                            );
                        }
                    }
                    $groups = CollmexGroup::find();
                    if ($groups) {
                        foreach ($groups as $group) {
                            $groupNames[(string) (int) $group->getCollmexId()] = $group->getName() . '';
                        }
                    }
                }

                return $this->jsonResponse(ShopDetail::payload($this->audience(), array(
                    'id' => (int) $item->id,
                    'name' => $item->name . '',
                    'street' => $item->street . '',
                    'products' => $products,
                    'offertypes' => $offertypes,
                    'speaker_only' => count($offerTypeIds) === 1 && $offerTypeIds[0] === 3,
                    'city' => $item->city . '',
                    'zip' => $item->zip . '',
                    'web' => $item->web . '',
                    'email' => $item->email . '',
                    'phone' => $item->getPhone() . '',
                    'phone2' => $item->getPhone2() . '',
                    'department' => $item->getDepartment() . '',
                    'firstname' => $item->getFirstname() . '',
                    'surname' => $item->getSurname() . '',
                    'note' => $item->getNote() . '',
                    'inactive' => $item->getInactive() . '',
                    'no_mailings' => $item->getNoMailings() . '',
                    'collmex_address_id' => $item->getCollmexAddressId() . '',
                    'collmex_address_groups' => $item->getCollmexAddressGroups() . '',
                    'contacts' => $contacts,
                ), $groupNames));
            }
        }
        
        return $this->jsonResponseError();
    }
}
