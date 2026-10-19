<?php

use Phalcon\Mvc\Controller;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class ControllerBase extends Controller
{
    public $mail_from;
    public $mail_loaded;
    public $mail_mailer;

    public function loadMailer() {

        if($this->mail_loaded !== true) {

            $user = rawurlencode((string) $this->config->smtp->user);
            $pass = rawurlencode((string) $this->config->smtp->pass);
            $host = (string) $this->config->smtp->host;
            $dsn = 'smtps://' . $user . ':' . $pass . '@' . $host . ':465';
            $this->mail_mailer = new Mailer(Transport::fromDsn($dsn));
            $this->mail_loaded = true;
        }


    }

    public function mail($to, $subject, $body, $from = false) {

        if(!is_array($to)){
            $to = [$to];
        }

        $sender = MailAddress::parse($this->config->smtp->from);
        if ($sender === null) {
            return false;
        }

        try {
            $this->loadMailer();
            $message = (new Email())
                ->from(new Address($sender['email'], $sender['name']))
                ->subject($subject)
                ->text($body)
            ;

            foreach ($to as $recipient) {
                $message->addTo($recipient);
            }

            $reply = MailAddress::parse($from === false ? $this->config->smtp->from : $from);
            if ($reply !== null) {
                $message->replyTo(new Address($reply['email'], $reply['name']));
            }

            $this->mail_mailer->send($message);
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }


    public function jsonResponse($data, $status = 1) {
        
        $return = [
            'status' => $status,
            'data' => $data
        ];
        
        $this->view->disable();

        //Create a response instance
        $response = new \Phalcon\Http\Response();
        
        $response->setContentType('application/json', 'UTF-8');

        //Set the content of the response
        $response->setContent(json_encode($return));

        //Return the response
        return $response;
    }
    
    public function jsonResponseError($message = false) {
        return $this->jsonResponse([
            'msg' => $message
        ],0);
    }
}
