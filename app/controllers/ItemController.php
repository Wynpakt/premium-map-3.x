<?php

class ItemController extends ControllerBase
{

    public function getAllAction() {
        $items = Item::find();
        $cleaned = [];
        foreach($items as $item) {
            if (MarkerQuery::isHidden($item->getCollmexAddressGroups())) {
                continue;
            }
            $cleaned_item = array();

            $products = array();
            foreach ($item->products as $p){
                $products[] = $p->name;
            }
            if(count($products) == 0) {
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
            if (MarkerQuery::isHidden($itemRow->getCollmexAddressGroups())) {
                continue;
            }
            if (count($itemRow->offertypes) == 0 || count($itemRow->products) == 0) {
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
     * gets one marker item with details
     * 
     * @return json
     */
    public function apiGetAction() {
        
        if($id = (int)$this->dispatcher->getParam('id')) {
            if($item = Item::findFirst($id)) {
                if (MarkerQuery::isHidden($item->getCollmexAddressGroups())) {
                    return $this->jsonResponseError();
                }

                $products = [];
                
                $out = [];

                foreach ($item->products as $p){
                    $products[] = $p->name;
                }

                $offertypes = [];
                foreach ($item->offertypes as $ot) {
                    $offertypes[] = $ot->name;
                }

                $street = $item->street;

                /*
                 * if only speaker dont send the streenname
                 */
                if(count($item->offertypes) == 1 && $item->offertypes[0]->id == 3) {
                    $street = '';
                }

                return $this->jsonResponse([
                    'id' => (int)$item->id,
                    'name' => $item->name.'',
                    'street' => $street.'',
                    'products' => $products,
                    'offertypes' => $offertypes,
                    'city' => $item->city.'',
                    'zip' => $item->zip.'',
                    'web' => $item->web.'',
                    'email' => $item->email.'',
                    'uri' => $item->id
                ]);
            }
        }
        
        return $this->jsonResponseError();
    }
}
