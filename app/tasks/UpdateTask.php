<?php

class UpdateTask extends BaseTask
{
    const GEOCODE_ATTEMPTS = 4;
    const GEOCODE_RETRY_SECONDS = 2;
    const GEOCODE_MISSES_BEFORE_STOP = 5;

    /*
     * static products
     */
    private $product_cola;

    private $product_bier;

    private $product_frohlunder;

    private $product_muntermate;

    /*
     * static offertypes
     */
    private $offertype_laden;

    private $offertype_haendler;

    private $offertype_sprecher;

    private $offertype_webshop;

    /*
     * static collmex products
     */
    private $collmex_product_cola;

    private $collmex_product_bier;

    private $collmex_product_frohlunder;

    private $collmex_product_muntermate;

    /*
     * static collmex offertypes
     */
    private $collmex_offertype_sprecher;

    private $collmex_offertype_laden;

    private $collmex_offertype_haendler;

    private $collmex_offertype_webshop;

    public function initialize()
    {
        /*
         * collect recors as in db scheme static defined
         */
        $this->product_cola = Product::findFirst(1);
        $this->product_bier = Product::findFirst(2);
        $this->product_frohlunder = Product::findFirst(3);
        $this->product_muntermate = Product::findFirst(4);

        $this->offertype_laden = Offertype::findFirst(1);
        $this->offertype_haendler = Offertype::findFirst(2);
        $this->offertype_sprecher = Offertype::findFirst(3);
        $this->offertype_webshop = Offertype::findFirst(4);

        /*
         * define group ids from collmex
         */
        $this->collmex_product_cola = $this->product_cola->getCollmexId();
        $this->collmex_product_bier = $this->product_bier->getCollmexId();
        $this->collmex_product_frohlunder = $this->product_frohlunder->getCollmexId();
        $this->collmex_product_muntermate = $this->product_muntermate->getCollmexId();

        $this->collmex_offertype_laden = 8;
        $this->collmex_offertype_haendler = 9;
        $this->collmex_offertype_sprecher = 7;
        $this->collmex_offertype_webshop = 112;
    }

    /**
     * Main Action will check all collmex customers and update them in mysql db.
     * A changed street, zip or city is geocoded again. The existing pin stays
     * until Photon returns a new coordinate.
     */
    public function mainAction()
    {
        $lock = SyncLock::acquire(SyncLock::PATH);
        if ($lock === null) {
            $this->error('Sync-Sperre nicht angelegt: ' . SyncLock::PATH);
        }
        if ($lock === false) {
            echo 'Sync läuft schon.' . PHP_EOL;
            return;
        }

        /*
         * get all customers from collmex
         */
        $records = $this->collmexAddressGet(CollmexFetch::addresses($this->audience()));
        if (SyncRun::emptyFetch($records)) {
            $this->error('Collmex lieferte keine Adressen.');
        }
        $this->syncGroupNames();
        $contactsByParent = CollmexAddress::contactsFor($records);
        $storeStaff = MapAudience::storesPhone($this->audience());

        $new_records = 0;
        $updated_records = 0;
        $address_changes = 0;
        $unused_records = 0;
        $unchanged_records = 0;
        $failed_saves = 0;
        $itemsByAddress = $this->itemsByAddressId();
        $offersByItem = $this->idsByItem('ItemHasOffertype', 'getOffertypeId');
        $productsByItem = $this->idsByItem('ItemHasProduct', 'getProductId');
        $contactsByItem = $this->contactsByItem();

            foreach (CollmexAddress::shops($records) as $record) {

                if ($data = $this->addressCleanup($record)) {
                    $collmex_groups = explode(',',$data['collmex_address_groups']);

                    /*
                    * Public map: only group 124.
                    * Internal map: every address that already has a place and a Collmex id.
                    *
                    * 16: ehemalige Kunden
                    * 93: möchte auf eigenen Wunsch nicht auf die Landkarte
                    * 124: möchten auf die Landkarte
                    */
                    if (!MapAudience::storesAddress($this->audience(), $collmex_groups)) {
                        continue;
                    }
                    
                    $addressId = (int) $data['address_id'];
                    $parentContacts = ($storeStaff && isset($contactsByParent[$addressId]))
                        ? $contactsByParent[$addressId]
                        : array();
                    $incoming = $this->incomingRow($data, $storeStaff, $parentContacts);
                    $item = isset($itemsByAddress[$addressId]) ? $itemsByAddress[$addressId] : null;
                    if ($item && SyncRow::same(
                        $this->storedRow($item, $offersByItem, $productsByItem, $contactsByItem),
                        $incoming
                    )) {
                        $unchanged_records++;
                        continue;
                    }

                    /*
                    * check if item exists by customer_id
                    * otherwise create new item
                    */
                    if ($item) {
                        $changed = AddressChange::addressDiffers(
                            $item->getStreet(),
                            $item->getZip(),
                            $item->getCity(),
                            $incoming['street'],
                            $incoming['zip'],
                            $incoming['city']
                        );
                        if ($changed) {
                            $next = AddressChange::nextGeoState(
                                true,
                                $item->getLat(),
                                $item->getLng(),
                                $item->getGeolocateCount(),
                                1
                            );
                            $item->setLat($next['lat']);
                            $item->setLng($next['lng']);
                            $item->setGeolocateCount($next['geolocate_count']);
                            $item->setGeocodePending($next['geocode_pending']);
                            $address_changes++;
                        }

                        $updated_records++;

                    } else {
                        $item = new Item();
                        $item->setGeocodePending(1);
                        $new_records++;
                    }

                    $this->applyRow($item, $incoming);

                    $item->deleteOffertypes();
                    $item->deleteProducts();

                    /*
                     * Map Offertypes and Products from collmex to Db
                     */
                    if($offertypes = $this->mapOffertypes($data['address_group'])) {
                        $item->setOffertypes($offertypes);
                    }
                    /*
                    * Public map: a row with no shop type is not a marker.
                    * Internal map: the address stays, even without a type or product.
                    */
                    elseif (!MapAudience::keepsWithoutOfferType($this->audience())) {
                        $unused_records++;
                        echo $item->name . ': ' . $item->city . '; ';
                        $this->deleteContacts($item);
                        $item->delete();
                        continue;
                    }
                    
                    if($products = $this->mapProducts($data['address_group'])) {
                        $item->setProducts($products);
                    }

                    /*
                     * Save all the funny stuff
                     */
                    if (!$item->save()) {
                        $failed_saves++;
                        echo 'Fehler: could not save item address_id => ' . $data['address_id'] . PHP_EOL;
                        continue;
                    }
                    $this->replaceContacts($item, $incoming['contacts']);
                }
            }

            $deletecItems = $this->deleteOldEntries($records, $itemsByAddress);

            echo PHP_EOL;

            echo $new_records . ' new Map Items' . PHP_EOL;
            echo $updated_records . ' Items Updated' . PHP_EOL;
            echo $unchanged_records . ' Items unchanged' . PHP_EOL;
            echo $address_changes . ' Address changes' . PHP_EOL;
            echo $unused_records . ' unused Records' . PHP_EOL;
            echo $deletecItems . ' deleted Records' . PHP_EOL;
        if ($failed_saves > 0) {
            exit(1);
        }
    }

    public function deleteOldEntries($collmexItems = null, $databaseItems = null) {
        if ($collmexItems === null) {
            $collmexItems = $this->collmexAddressGet(CollmexFetch::addresses($this->audience()));
        }
        if (SyncRun::emptyFetch($collmexItems)) {
            echo 'Collmex lieferte keine Adressen, nichts gelöscht.' . PHP_EOL;
            return 0;
        }

        $cleaned = array();
        foreach (CollmexAddress::shops($collmexItems) as $collmexItem) {
            $data = $this->addressCleanup($collmexItem);
            if ($data) {
                $cleaned[] = $data;
            }
        }
        if (count($cleaned) === 0) {
            echo 'Collmex lieferte keine brauchbaren Adressen, nichts gelöscht.' . PHP_EOL;
            return 0;
        }

        $kept = SyncCleanup::keptIds($cleaned, $this->audience());
        $deleted = 0;
        if ($databaseItems === null) {
            $databaseItems = Item::find();
        }
        foreach ($databaseItems as $databaseItem) {
            $id = (string) (int) $databaseItem->getCollmexAddressId();
            if (isset($kept[$id])) {
                continue;
            }
            $databaseItem->deleteOffertypes();
            $databaseItem->deleteProducts();
            $this->deleteContacts($databaseItem);
            $databaseItem->delete();
            $deleted++;
        }

        return $deleted;
    }

    public function mapProducts($collmex_group_ids) {

        $out = [];

        if(isset($collmex_group_ids[$this->collmex_product_cola])) {
            $out[] = $this->product_cola;
        }

        if(isset($collmex_group_ids[$this->collmex_product_bier])) {
            $out[] = $this->product_bier;
        }

        if(isset($collmex_group_ids[$this->collmex_product_frohlunder])) {
            $out[] = $this->product_frohlunder;
        }

        if(isset($collmex_group_ids[$this->collmex_product_muntermate])) {
            $out[] = $this->product_muntermate;
        }

        return $out;
    }

    public function mapOffertypes($collmex_group_ids) {

        $out = [];

        if(isset($collmex_group_ids[$this->collmex_offertype_laden])) {
            $out[] = $this->offertype_laden;
        }

        if(isset($collmex_group_ids[$this->collmex_offertype_haendler])) {
            $out[] = $this->offertype_haendler;
        }

        if(isset($collmex_group_ids[$this->collmex_offertype_sprecher])) {
            $out[] = $this->offertype_sprecher;
        }

        if(isset($collmex_group_ids[$this->collmex_offertype_webshop])) {
            $out[] = $this->offertype_webshop;
        }
        return $out;
    }
    
    /*
     * Speakers are located by city only. A single unanswered Photon call
     * is retried. false means Photon still did not answer.
     */
    private function locateItem($item)
    {
        for ($try = 1; $try <= self::GEOCODE_ATTEMPTS; $try++) {
            if (count($item->offertypes) == 1 && $item->offertypes[0]->id == $this->offertype_sprecher->id) {
                $geo = $this->getCoordinates('', '', $item->city, $item->country);
            } else {
                $geo = $this->getCoordinates($item->street, $item->zip, $item->city, $item->country);
            }
            if ($geo !== false) {
                return $geo;
            }
            if ($try < self::GEOCODE_ATTEMPTS) {
                sleep(self::GEOCODE_RETRY_SECONDS);
            }
        }

        return false;
    }

    /*
     * CLI action updating geo coordinates only
     */
    public function geoAction() {
        if (PhotonIndexRefresh::isRefreshing()) {
            echo 'Photon-Index wird ausgetauscht. Dieser Lauf macht kein Geocoding.' . PHP_EOL;
            return;
        }

        if($items = Item::find('geolocate_count < 20')) {
            
            $new_updated_items = 0;
            $faled_items = 0;
            $photonMisses = 0;
            
            foreach ($items as $item) {
                $missing = ((int)$item->lat == 0) || ((int)$item->lng == 0);
                $pending = (int)$item->getGeocodePending() === 1;
                if($missing || $pending) {
                    if (!MapAddress::complete($item->street, $item->zip, $item->city)) {
                        continue;
                    }

                    /*
                     * DE, AT and CH have a local Photon. Another country is skipped
                     * so one foreign address does not stop the rest of the run.
                     */
                    if ($this->photonBase($item->country) === null) {
                        $item->geolocate_count = (int) $item->geolocate_count + 1;
                        $item->save();
                        $faled_items++;
                        echo 'kein Endpunkt ' . $item->country . PHP_EOL;
                        continue;
                    }

                    $geo = $this->locateItem($item);

                    if ($geo === false) {
                        $photonMisses++;
                        echo PHP_EOL . 'Photon nicht erreichbar, Versuch ' . $photonMisses . PHP_EOL;
                        if ($photonMisses >= self::GEOCODE_MISSES_BEFORE_STOP) {
                            echo 'Abbruch: Photon nicht erreichbar. Später erneut: php app/cli.php update geo' . PHP_EOL;
                            break;
                        }
                        sleep(self::GEOCODE_RETRY_SECONDS);
                        continue;
                    }
                    $photonMisses = 0;

                    $item->geolocate_count = (int)$item->geolocate_count + 1;

                    if($geo) {
                        $item->setLat($geo['lat']);
                        $item->setLng($geo['lng']);
                        $item->setGeocodePending(0);
                        
                        echo ".";
                        $new_updated_items++;
                    }
                    else {
                        echo $item->name . ': ' . $item->city . '; ';
                        $faled_items++;
                    }
                    
                    $item->save();
                } 
            }
            
            echo PHP_EOL;
            
            echo $new_updated_items . ' successful crawled :)' . PHP_EOL;
            echo $faled_items . ' not locateable' . PHP_EOL;
        }
        
    }

    /**
     * Give up-items another Photon pass: no coordinates yet, or a pending address change.
     */
    public function geoResetAction()
    {
        $items = Item::find();
        $reset = 0;
        foreach ($items as $item) {
            $missing = ((int) $item->lat == 0) || ((int) $item->lng == 0);
            $pending = (int) $item->getGeocodePending() === 1;
            if (!$missing && !$pending) {
                continue;
            }
            $item->setGeolocateCount(0);
            if ($item->save()) {
                $reset++;
            }
        }
        echo $reset . ' items queued for php app/cli.php update geo' . PHP_EOL;
    }

    /**
     * Move pins whose reverse postcode is not the Collmex PLZ, when Photon
     * can place that PLZ. Without "apply" this only prints the plan.
     */
    public function geoRepairAction($mode = '')
    {
        if (PhotonIndexRefresh::isRefreshing()) {
            echo 'Photon-Index wird ausgetauscht. Dieser Lauf macht kein Geocoding.' . PHP_EOL;
            return;
        }

        $apply = ($mode === 'apply');
        $moved = 0;
        $kept = 0;
        $unresolved = 0;
        $skipped = 0;

        foreach (Item::find() as $item) {
            $street = trim((string) $item->getStreet());
            $zip = trim((string) $item->getZip());
            $missing = ((int) $item->lat == 0) || ((int) $item->lng == 0);
            $speaker = count($item->offertypes) == 1
                && (int) $item->offertypes[0]->id === (int) $this->offertype_sprecher->id;
            if ($missing || $street === '' || $zip === '' || $speaker) {
                $skipped++;
                continue;
            }

            $base = $this->photonBase($item->getCountry());
            if ($base === null) {
                echo 'Kein Photon-Endpunkt für ' . $item->getCountry() . PHP_EOL;
                return;
            }

            $reversed = $this->photonFeatures(
                PhotonGeocoder::reverseEndpoint($base, $item->getLat(), $item->getLng())
            );
            if ($reversed === false) {
                echo PHP_EOL . 'Abbruch: Photon nicht erreichbar.' . PHP_EOL;
                return;
            }

            $pinZip = '';
            if (count($reversed) > 0) {
                $pinZip = PhotonGeocoder::normalizePostcode($reversed[0]['postcode']);
            }
            if ($pinZip !== '' && $pinZip === PhotonGeocoder::normalizePostcode($zip)) {
                $kept++;
                continue;
            }

            $geo = $this->getCoordinates($street, $zip, $item->getCity(), $item->getCountry());
            if ($geo === false) {
                echo PHP_EOL . 'Abbruch: Photon nicht erreichbar.' . PHP_EOL;
                return;
            }
            if (!is_array($geo) || PhotonGeocoder::normalizePostcode($geo['postcode']) !== PhotonGeocoder::normalizePostcode($zip)) {
                $unresolved++;
                echo 'offen  #' . $item->getCollmexAddressId()
                    . ' ' . $item->getName()
                    . ' ' . $street . ', ' . $zip . ' ' . $item->getCity()
                    . ' pin=' . $pinZip . PHP_EOL;
                continue;
            }

            $meters = PhotonGeocoder::meters($item->getLat(), $item->getLng(), $geo['lat'], $geo['lng']);
            echo ($apply ? 'verschoben' : 'würde verschieben')
                . '  #' . $item->getCollmexAddressId()
                . ' ' . $item->getName()
                . ' ' . $street . ', ' . $zip . ' ' . $item->getCity()
                . ' pin=' . $pinZip
                . ' -> ' . $geo['postcode'] . ' ' . $geo['street']
                . ' (' . $meters . ' m)' . PHP_EOL;
            $moved++;
            if ($apply) {
                $item->setLat($geo['lat']);
                $item->setLng($geo['lng']);
                $item->setGeocodePending(0);
                if (!$item->save()) {
                    echo 'Speichern fehlgeschlagen #' . $item->getCollmexAddressId() . PHP_EOL;
                }
            }
        }

        echo $moved . ($apply ? ' verschoben, ' : ' zu verschieben, ')
            . $kept . ' Postleitzahl stimmt, '
            . $unresolved . ' ohne passenden Treffer, '
            . $skipped . ' übersprungen' . PHP_EOL;
        if (!$apply) {
            echo 'Nur Anzeige. Schreiben: php app/cli.php update geo-repair apply' . PHP_EOL;
        }
    }

    /**
     * Print Collmex addresses that share a normalized street+zip+city, or the same pin.
     * Does not delete or hide anything.
     */
    public function duplicatesAction()
    {
        $rows = array();
        foreach (Item::find() as $item) {
            $rows[] = array(
                'id' => (int) $item->id,
                'collmex_address_id' => $item->getCollmexAddressId(),
                'name' => $item->getName(),
                'street' => $item->getStreet(),
                'zip' => $item->getZip(),
                'city' => $item->getCity(),
                'lat' => $item->getLat(),
                'lng' => $item->getLng(),
            );
        }

        $groups = DuplicateFinder::groups($rows);
        echo 'Address groups: ' . count($groups['address']) . PHP_EOL;
        foreach ($groups['address'] as $key => $group) {
            echo PHP_EOL . $key . PHP_EOL;
            foreach ($group as $row) {
                echo '  #' . $row['collmex_address_id'] . ' ' . $row['name'] . ' (' . $row['id'] . ')' . PHP_EOL;
            }
        }

        echo PHP_EOL . 'Same-point groups: ' . count($groups['point']) . PHP_EOL;
        foreach ($groups['point'] as $key => $group) {
            $ids = array();
            foreach ($group as $row) {
                $ids[] = $row['collmex_address_id'] . ' ' . $row['name'];
            }
            echo '  ' . $key . ' => ' . implode(' | ', $ids) . PHP_EOL;
        }
    }

    /**
     * Sample Photon street vs Collmex street. Disagreement is data, not another geocoder.
     */
    public function streetsAction()
    {
        $checked = 0;
        $differ = 0;
        foreach (Item::find() as $item) {
            if (trim((string) $item->getStreet()) === '' || trim((string) $item->getCity()) === '') {
                continue;
            }
            $geo = $this->getCoordinates($item->getStreet(), $item->getZip(), $item->getCity(), $item->getCountry());
            if ($geo === false) {
                echo 'Abbruch: Photon nicht erreichbar.' . PHP_EOL;
                break;
            }
            $checked++;
            if (!is_array($geo)) {
                echo 'not found  #' . $item->getCollmexAddressId() . ' ' . $item->getStreet() . ', ' . $item->getZip() . ' ' . $item->getCity() . PHP_EOL;
                continue;
            }
            if (StreetCompare::differs($item->getStreet(), $geo['street'])) {
                $differ++;
                echo 'differs  #' . $item->getCollmexAddressId()
                    . ' collmex="' . $item->getStreet()
                    . '" photon="' . $geo['street']
                    . '" ' . $item->getZip() . ' ' . $item->getCity() . PHP_EOL;
            }
        }
        echo $checked . ' checked, ' . $differ . ' street names differ from Photon' . PHP_EOL;
    }

    private function audience()
    {
        return MapAudience::fromConfig($this->config);
    }

    private function syncGroupNames()
    {
        if (!MapAudience::isInternal($this->audience())) {
            return;
        }
        $rows = $this->collmexGet(CollmexFetch::groups());
        if (!is_array($rows)) {
            return;
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $mapped = CollmexAddressGroup::fromRow($row);
            if ($mapped === null) {
                continue;
            }
            $group = CollmexGroup::findFirst('collmex_id = ' . (int) $mapped['id']);
            if (!$group) {
                $group = new CollmexGroup();
                $group->setCollmexId($mapped['id']);
            } elseif ((string) $group->getName() === (string) $mapped['name']) {
                continue;
            }
            $group->setName($mapped['name']);
            $group->save();
        }
    }

    private function staffText(array $data, $key)
    {
        return isset($data[$key]) ? trim((string) $data[$key]) : '';
    }

    private function itemsByAddressId()
    {
        $items = Item::find();
        if ($items === false) {
            $this->error('Adressen konnten nicht gelesen werden.');
        }
        $byAddress = array();
        foreach ($items as $item) {
            $byAddress[(int) $item->getCollmexAddressId()] = $item;
        }

        return $byAddress;
    }

    private function idsByItem($class, $method)
    {
        $rows = call_user_func(array($class, 'find'));
        if ($rows === false) {
            $this->error('Zuordnungen konnten nicht gelesen werden.');
        }
        $out = array();
        foreach ($rows as $row) {
            $out[(int) $row->getItemId()][] = (int) call_user_func(array($row, $method));
        }

        return $out;
    }

    private function contactsByItem()
    {
        $rows = ItemContact::find();
        if ($rows === false) {
            $this->error('Ansprechpartner konnten nicht gelesen werden.');
        }
        $out = array();
        foreach ($rows as $row) {
            $out[(int) $row->getItemId()][] = array(
                'collmex_id' => (int) $row->getCollmexId(),
                'name' => (string) $row->getName(),
                'department' => (string) $row->getDepartment(),
                'phone' => (string) $row->getPhone(),
                'phone2' => (string) $row->getPhone2(),
                'email' => (string) $row->getEmail(),
            );
        }

        return $out;
    }

    private function modelIds($models)
    {
        $ids = array();
        foreach ($models as $model) {
            $ids[] = (int) $model->getId();
        }

        return $ids;
    }

    private function replaceContacts($item, array $contacts)
    {
        $this->deleteContacts($item);
        foreach ($contacts as $contact) {
            $row = new ItemContact();
            $row->setItemId((int) $item->getId());
            $row->setCollmexId((int) $contact['collmex_id']);
            $row->setName($contact['name']);
            $row->setDepartment($contact['department']);
            $row->setPhone($contact['phone']);
            $row->setPhone2($contact['phone2']);
            $row->setEmail($contact['email']);
            $row->save();
        }
    }

    private function deleteContacts($item)
    {
        $id = (int) $item->getId();
        if ($id <= 0) {
            return;
        }
        $rows = ItemContact::find('item_id = ' . $id);
        if (!$rows) {
            return;
        }
        foreach ($rows as $row) {
            $row->delete();
        }
    }

    private function incomingRow(array $data, $storeStaff, array $contacts)
    {
        $phone = isset($data['phone']) ? trim((string) $data['phone']) : '';

        return array(
            'name' => isset($data['name']) ? (string) $data['name'] : '',
            'street' => isset($data['street']) ? (string) $data['street'] : '',
            'zip' => preg_replace('/[^0-9]/', '', isset($data['zip']) ? (string) $data['zip'] : ''),
            'city' => isset($data['city']) ? (string) $data['city'] : '',
            'country' => isset($data['country']) ? (string) $data['country'] : '',
            'web' => isset($data['web']) ? (string) $data['web'] : '',
            'email' => ShopEmail::stored(isset($data['email']) ? $data['email'] : ''),
            'phone' => $storeStaff ? $phone : '',
            'phone2' => $storeStaff ? $this->staffText($data, 'phone2') : '',
            'department' => $storeStaff ? $this->staffText($data, 'department') : '',
            'firstname' => $storeStaff ? $this->staffText($data, 'firstname') : '',
            'surname' => $storeStaff ? $this->staffText($data, 'surname') : '',
            'note' => $storeStaff ? $this->staffText($data, 'note') : '',
            'inactive' => $storeStaff && $this->staffText($data, 'inactive') === '1' ? 1 : 0,
            'no_mailings' => $storeStaff && $this->staffText($data, 'no_mailings') === '1' ? 1 : 0,
            'address_id' => (int) $data['address_id'],
            'groups' => isset($data['collmex_address_groups']) ? (string) $data['collmex_address_groups'] : '',
            'offertypes' => $this->modelIds($this->mapOffertypes($data['address_group'])),
            'products' => $this->modelIds($this->mapProducts($data['address_group'])),
            'contacts' => $storeStaff ? $contacts : array(),
        );
    }

    private function applyRow($item, array $row)
    {
        $item->setEmail($row['email']);
        $item->setPhone($row['phone']);
        $item->setPhone2($row['phone2']);
        $item->setDepartment($row['department']);
        $item->setFirstname($row['firstname']);
        $item->setSurname($row['surname']);
        $item->setNote($row['note']);
        $item->setInactive($row['inactive']);
        $item->setNoMailings($row['no_mailings']);
        $item->setCountry($row['country']);
        $item->setStreet($row['street']);
        $item->setZip($row['zip']);
        $item->setCity($row['city']);
        $item->setCollmexAddressId((int) $row['address_id']);
        $item->setName($row['name']);
        $item->setWeb($row['web']);
        $item->setCollmexAddressGroups($row['groups']);
    }

    private function storedRow($item, array $offersByItem, array $productsByItem, array $contactsByItem)
    {
        $id = (int) $item->getId();

        return array(
            'name' => (string) $item->getName(),
            'street' => (string) $item->getStreet(),
            'zip' => (string) $item->getZip(),
            'city' => (string) $item->getCity(),
            'country' => (string) $item->getCountry(),
            'web' => (string) $item->getWeb(),
            'email' => (string) $item->getEmail(),
            'phone' => (string) $item->getPhone(),
            'phone2' => (string) $item->getPhone2(),
            'department' => (string) $item->getDepartment(),
            'firstname' => (string) $item->getFirstname(),
            'surname' => (string) $item->getSurname(),
            'note' => (string) $item->getNote(),
            'inactive' => (int) $item->getInactive(),
            'no_mailings' => (int) $item->getNoMailings(),
            'groups' => (string) $item->getCollmexAddressGroups(),
            'offertypes' => isset($offersByItem[$id]) ? $offersByItem[$id] : array(),
            'products' => isset($productsByItem[$id]) ? $productsByItem[$id] : array(),
            'contacts' => isset($contactsByItem[$id]) ? $contactsByItem[$id] : array(),
        );
    }

    /**
     * Check Collmex Data before save it to the database
     * 
     * @param Collmex DataArray $data
     * @return boolean
     * 
     */
    private function addressCleanup($data) {

        // check is basic fields are exists
        if(!isset($data['city']) || !isset($data['zip']) || !isset($data['address_id'])) {
            return false;
        }

        /*
         * Check Address Group Field
         */
        $data['address_group'] = $this->checkAddressGroups($data);

        if(!$data['address_group']) {
            return false;
        }

        $data['collmex_address_groups'] = implode(',',$data['address_group']);

        /*
         * typecast Address Id
         */
        $data['address_id'] = (int)$data['address_id'];

        /*
         * fill name Field
         */
        $name = '';
        if(isset($data['name'])) {
            $name = trim($data['name'].'');
        }
        

        if($name == '' && isset($data['firstname']) && isset($data['surname'])) {
            $name = trim($data['firstname'].' ' . $data['surname']);
        }
        
        if(substr($name, 0,1) == '"' && substr($name, -1) == '"') {
            $name = substr($name, 1, -1);
        }
        $data['name'] = $name;

        /*
         * cleanup web address field
         */
        $data['web'] = isset($data['web']) ? trim($data['web']) : '';
        if($data['web'] != '') {
            $data['web'] = $this->addhttp($data['web']);
        }

        return $data;
        
    }

    /*
     * parse csv simple value to array
     * then check if item is map item
     */
    private function checkAddressGroups($record) {

        $tmp = explode(',',$record['address_group']);

        $groups = [];
        foreach ($tmp as $key => $value) {
            $groups[(int)$value] = (int)$value;
        }

        // todo: address group check before insert

        if(empty($groups)) {
            return false;
        }

        return $groups;
    }
}