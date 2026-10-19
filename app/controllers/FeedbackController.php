<?php

class FeedbackController extends ControllerBase
{
    public function indexAction() {}

    public function zipmailAction() {
        if(
            isset($_POST['email']) &&
            isset($_POST['feedback']) && 
            isset($_POST['id'])
        ) {

            $id = (int)$_POST['id'];
            $from = false;

            if($item = Item::findFirst($id)) {
                $country = $item->country;
                $zip = $item->zip;


                /*
                 * generate feedback mail content
                 */
                $feedback = strip_tags($_POST['feedback']);

                $feedback .= "\n\n===========================================\n\nLandkarten Adresse um die es geht:\n";

                $feedback .= $item->name . "\n" . $item->street . "\n" . $item->zip . " " . $item->city . "\nCollmex Address ID: " . $item->collmex_address_id;

                /*
                 * gnereate feedback mail subject
                 */
                $subject = 'Premium Landkarte: ' . $item->name;

                /*
                 * set from mail if valid mail is posted
                 */
                if(filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
                    $from = $_POST['email'];
                }

                $contacts = FeedbackRouter::parseLines(file_get_contents('../app/config/feedback.txt'));
                $contact_email = FeedbackRouter::route($contacts, $country, $zip);

                if($this->mail($contact_email, $subject, $feedback, $from)) {
                    return $this->jsonResponse([
                        'msg' => 'Nachricht wurde an ' . $contact_email . ' versendet.',
                    ]);
                } else {
                    return $this->jsonResponseError('Nachricht konnte nicht gesendet werden, weil die eMail nicht rausging.');
                }
            }

            return $this->jsonResponseError('Nachricht konnte nicht gesendet werden, weil die Id nicht bekannt ist.');

        }
        
    }

    /**
     * Report a shop that is not on the map yet. No item id required.
     */
    public function reportAction() {
        if (!isset($_POST['email'], $_POST['feedback'], $_POST['name'])) {
            return $this->jsonResponseError('Name, E-Mail und Nachricht werden gebraucht.');
        }

        $email = trim((string) $_POST['email']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->jsonResponseError('Die E-Mail-Adresse ist ungültig.');
        }

        $name = trim(strip_tags((string) $_POST['name']));
        $street = isset($_POST['street']) ? trim(strip_tags((string) $_POST['street'])) : '';
        $zip = isset($_POST['zip']) ? trim(strip_tags((string) $_POST['zip'])) : '';
        $city = isset($_POST['city']) ? trim(strip_tags((string) $_POST['city'])) : '';
        $country = isset($_POST['country']) ? strtoupper(trim(strip_tags((string) $_POST['country']))) : '';
        $text = trim(strip_tags((string) $_POST['feedback']));

        if ($name === '' || $text === '') {
            return $this->jsonResponseError('Name und Nachricht dürfen nicht leer sein.');
        }

        $contacts = FeedbackRouter::parseLines(file_get_contents('../app/config/feedback.txt'));
        $contact_email = FeedbackRouter::route($contacts, $country, $zip);

        $body = $text
            . "\n\n===========================================\n\n"
            . "Gemeldete Verkaufsstelle (noch nicht auf der Landkarte):\n"
            . $name . "\n"
            . $street . "\n"
            . $zip . ' ' . $city . "\n"
            . $country . "\n";

        if ($this->mail($contact_email, 'Premium Landkarte: neue Verkaufsstelle ' . $name, $body, $email)) {
            return $this->jsonResponse(array(
                'msg' => 'Nachricht wurde an ' . $contact_email . ' versendet.',
            ));
        }

        return $this->jsonResponseError('Nachricht konnte nicht gesendet werden, weil die eMail nicht rausging.');
    }

}

