<?php

class thread_chats extends rcube_plugin {
    private $session;
    /**
     * @var rcmail
     */
    private $rcmail;
    private $sentFolder = 'Sent';
    /** @var rcube_message */
    private $message;

    function init()
    {
        $this->rcmail = rcmail::get_instance();
        $this->add_hook('message_part_after', array($this, 'message_part_after'));
        $this->add_hook('message_body_prefix', array($this, 'message_body_prefix'));
    }

    function message_body_prefix($args) {
        $this->message = $args['message'];
    }

    function rcmail_print_body($body, $part, $p = array())
    {
        global $RCMAIL;

        // trigger plugin hook
        $data = array('type' => $part->ctype_secondary, 'body' => $body, 'id' => $part->mime_id)
            + $p + array('safe' => false, 'plain' => false, 'inline_html' => true);

        // convert html to text/plain
        if ($data['plain'] && ($data['type'] == 'html' || $data['type'] == 'enriched')) {
            if ($data['type'] == 'enriched') {
                $data['body'] = rcube_enriched::to_html($data['body']);
            }

            $body = $RCMAIL->html2text($data['body']);
            $part->ctype_secondary = 'plain';
        }
        // text/html
        else if ($data['type'] == 'html') {
            $body = rcmail_wash_html($data['body'], $data, $part->replaces);
            $part->ctype_secondary = $data['type'];
        }
        // text/enriched
        else if ($data['type'] == 'enriched') {
            $body = rcube_enriched::to_html($data['body']);
            $body = rcmail_wash_html($body, $data, $part->replaces);
            $part->ctype_secondary = 'html';
        }
        else {
            // assert plaintext
            $body = $data['body'];
            $part->ctype_secondary = $data['type'] = 'plain';
        }

        // free some memory (hopefully)
        unset($data['body']);

        // plaintext postprocessing
        if ($part->ctype_secondary == 'plain') {
            $flowed = $part->ctype_parameters['format'] == 'flowed';
            $delsp = $part->ctype_parameters['delsp'] == 'yes';
            $body = rcmail_plain_body($body, $flowed, $delsp);
        }

        // allow post-processing of the message body
        return $body;
    }

    function message_part_after($args) {
        // array('type' => $part->ctype_secondary, 'body' => $body, 'id' => $part->mime_id)
        $part_type = $args['type'];
        $message_id = $this->message->get_header('Message-Id');
        /** @var rcube_imap_generic $connection */
        $connection = $this->rcmail->storage->conn;

        $receive_thread = $connection->thread($this->message->folder, 'REFERENCES',
            "HEADER References {$message_id}", true);

        $sent_thread = $connection->thread($this->sentFolder, 'REFERENCES',
            "HEADER References {$message_id}", true);


        $receive_uids = $receive_thread->get();
        $receive_messages = [];
        foreach($receive_uids as $uid) {
            $receive_messages[] = new rcube_message($uid, $this->message->folder);
        }

        $sent_uids = $sent_thread->get();
        $sent_messages = [];
        foreach ($sent_uids as $uid) {
            $sent_messages[] = new rcube_message($uid, $this->sentFolder);
        }

        $messages = array_merge($receive_messages, $sent_messages);
        usort($messages, function($a, $b) {
            return ($a->headers->timestamp < $b->headers->timestamp) ? -1 : 1;
        });

        $body_args = array(
            'safe'         => $this->message->is_safe || intval($_GET['_safe']),
            'plain'        => !$this->rcmail->config->get('prefer_html'),
            'body_class'   => 'rcmBody',
        );

        foreach($messages as $message) {
            $message_part = null;
            foreach($message->parts as $m_part) {
                if ($m_part->type == $part_type) {
                    $message_part = $m_part;
                    break;
                }
            }

            $body = $this->message->get_part_body($message_part->mime_id, true);
            $body = $this->rcmail_print_body($body, $message_part, $body_args);

            if ($message_part->ctype_secondary == 'html') {
                $body = rcmail_html4inline($body, $body_args);
            }


            var_dump($body);
        }
    }
}