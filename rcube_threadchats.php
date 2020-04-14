<?php

class rcube_threadchats extends rcube_plugin {
    /**
     * @var rcmail
     */
    private $rcmail;
    private $sentFolder = 'Sent';
    private $draftFolder = 'Draft';

    function init()
    {
        $this->rcmail = rcmail::get_instance();
        $this->sentFolder = $this->rcmail->config->get('sent_mbox');
        $this->draftFolder = $this->rcmail->config->get('drafts_mbox');

        $this->add_hook('message_part_after', array($this, 'message_part_after'));

        if ($this->rcmail->task == 'settings') {
            $this->add_texts('localization/', true);
            $this->add_hook('settings_actions', array($this, 'settings_actions'));

            $this->register_action('plugin.rcube_threadchats', array($this, 'rcube_threadchats_config'));
            $this->register_action('plugin.rcube_threadchats-save', array($this, 'rcube_threadchats_save'));
        }
    }

    function rcube_threadchats_save()
    {
        $user = $this->rcmail->user;
        $arr_prefs = $user->get_prefs();

        $_collapse_history = rcube_utils::get_input_value('_collapse_history', rcube_utils::INPUT_POST,
            true);

        if($_collapse_history && $_collapse_history == '1') {
            $arr_prefs['rcube_threadchats'] = array('_collapse_history' => true);
        } else {
            $arr_prefs['rcube_threadchats'] = array('_collapse_history' => false);
        }
        $user->save_prefs($arr_prefs);

        $this->register_handler('plugin.body', array($this, 'rcube_threadchats_form'));
        $this->rcmail->output->set_pagetitle($this->gettext('thread_chats'));

        $this->rcmail->overwrite_action('plugin.rcube_threadchats');
        $this->rcmail->output->send('plugin');
    }

    function rcube_threadchats_config()
    {
        $this->register_handler('plugin.body', array($this, 'rcube_threadchats_form'));
        $this->rcmail->output->set_pagetitle($this->gettext('thread_chats'));

        $this->rcmail->output->send('plugin');
    }

    function rcube_threadchats_form()
    {
        $user = $this->rcmail->user;
        $arr_prefs = $user->get_prefs();
        $check_value = (isset($arr_prefs['rcube_threadchats']) &&
            isset($arr_prefs['rcube_threadchats']['_collapse_history']) &&
            $arr_prefs['rcube_threadchats']['_collapse_history']) ? '1': '0';

        $table = new html_table(array('cols' => 2, 'class' => 'propform'));

        $input_checkbox = new html_checkbox(array('name' => '_collapse_history', 'id' => 'collapse_history',
            'checked' => false, 'value' => '1'));

        $table->add('title', html::label('collapse_history', rcube::Q($this->gettext('collapse_history'))));
        $table->add(null, $input_checkbox->show($check_value));

        $this->rcmail->output->add_gui_object('threadchatsfrm', 'rcube_threadchats-form');

        $this->include_script('rcube_threadchats.js');

        $form = $this->rcmail->output->form_tag(array(
            'id'     => 'rcube_threadchats-form',
            'name'   => 'rcube_threadchats-form',
            'method' => 'post',
            'action' => './?_task=settings&_action=plugin.rcube_threadchats-save',
        ), $table->show());

        $submit_button = $this->rcmail->output->button(array(
            'command' => 'plugin.rcube_threadchats-save',
            'class'   => 'button mainaction submit',
            'label'   => 'save',
        ));
        $form_buttons = html::p(array('class' => 'formbuttons footerleft'), $submit_button);

        return html::div(array('id' => 'prefs-title', 'class' => 'boxtitle'), $this->gettext('thread_chats'))
            . html::div(array('class' => 'box formcontainer scroller'),
                html::div(array('class' => 'boxcontent formcontent'), $form)
                . $form_buttons);
    }

    function settings_actions($args)
    {
        // register as settings action
        $args['actions'][] = array(
            'action' => 'plugin.rcube_threadchats',
            'class'  => 'rcube_threadchats',
            'label'  => 'thread_chats',
            'title'  => 'thread_chats',
            'domain' => 'rcube_threadchats',
        );

        return $args;
    }

    function rcmail_message_headers($attrib, $MESSAGE, $headers=null)
    {
        global $PRINT_MODE, $RCMAIL;
        static $sa_attrib;

        // keep header table attrib
        if (is_array($attrib) && !$sa_attrib && !$attrib['valueof']) {
            $sa_attrib = $attrib;
        }
        else if (!is_array($attrib) && is_array($sa_attrib)) {
            $attrib = $sa_attrib;
        }

        if (!isset($MESSAGE)) {
            return false;
        }

        // get associative array of headers object
        if (!$headers) {
            $headers_obj = $MESSAGE->headers;
            $headers     = get_object_vars($MESSAGE->headers);
        }
        else if (is_object($headers)) {
            $headers_obj = $headers;
            $headers     = get_object_vars($headers_obj);
        }
        else {
            $headers_obj = rcube_message_header::from_array($headers);
        }

        // show these headers
        $standard_headers = array('subject', 'from', 'sender', 'to', 'cc', 'bcc', 'replyto',
            'mail-reply-to', 'mail-followup-to', 'date', 'priority');
        $exclude_headers = $attrib['exclude'] ? explode(',', $attrib['exclude']) : array();
        $output_headers  = array();

        foreach ($standard_headers as $hkey) {
            if ($headers[$hkey]) {
                $value = $headers[$hkey];
            }
            else if ($headers['others'][$hkey]) {
                $value = $headers['others'][$hkey];
            }
            else if (!$attrib['valueof']) {
                continue;
            }

            if (in_array($hkey, $exclude_headers)) {
                continue;
            }

            $ishtml       = false;
            $header_title = $RCMAIL->gettext(preg_replace('/(^mail-|-)/', '', $hkey));

            if ($hkey == 'date') {
                $header_value = $RCMAIL->format_date($value,
                    $PRINT_MODE ? $RCMAIL->config->get('date_long', 'x') : null);
            }
            else if ($hkey == 'priority') {
                if ($value) {
                    $header_value = html::span('prio' . $value, rcube::Q(rcmail_localized_priority($value)));
                    $ishtml       = true;
                }
                else {
                    continue;
                }
            }
            else if ($hkey == 'replyto') {
                if ($headers['replyto'] != $headers['from']) {
                    $header_value = rcmail_address_string($value, $attrib['max'], true,
                        $attrib['addicon'], $headers['charset'], $header_title);
                    $ishtml = true;
                }
                else {
                    continue;
                }
            }
            else if ($hkey == 'mail-reply-to') {
                if ($headers['mail-replyto'] != $headers['replyto']
                    && $headers['replyto'] != $headers['from']
                ) {
                    $header_value = rcmail_address_string($value, $attrib['max'], true,
                        $attrib['addicon'], $headers['charset'], $header_title);
                    $ishtml = true;
                }
                else {
                    continue;
                }
            }
            else if ($hkey == 'sender') {
                if ($headers['sender'] != $headers['from']) {
                    $header_value = rcmail_address_string($value, $attrib['max'], true,
                        $attrib['addicon'], $headers['charset'], $header_title);
                    $ishtml = true;
                }
                else {
                    continue;
                }
            }
            else if ($hkey == 'mail-followup-to') {
                $header_value = rcmail_address_string($value, $attrib['max'], true,
                    $attrib['addicon'], $headers['charset'], $header_title);
                $ishtml = true;
            }
            else if (in_array($hkey, array('from', 'to', 'cc', 'bcc'))) {
                $header_value = rcmail_address_string($value, $attrib['max'], true,
                    $attrib['addicon'], $headers['charset'], $header_title);
                $ishtml = true;
            }
            else if ($hkey == 'subject' && empty($value)) {
                $header_value = $RCMAIL->gettext('nosubject');
            }
            else {
                $value        = is_array($value) ? implode(' ', $value) : $value;
                $header_value = trim(rcube_mime::decode_header($value, $headers['charset']));
            }

            $output_headers[$hkey] = array(
                'title' => $header_title,
                'value' => $header_value,
                'raw'   => $value,
                'html'  => $ishtml,
            );
        }

        $plugin = $RCMAIL->plugins->exec_hook('message_headers_output', array(
            'output'  => $output_headers,
            'headers' => $headers_obj,
            'exclude' => $exclude_headers, // readonly
            'folder'  => $MESSAGE->folder, // readonly
            'uid'     => $MESSAGE->uid,    // readonly
        ));

        // single header value is requested
        if (!empty($attrib['valueof'])) {
            $row = $plugin['output'][$attrib['valueof']];
            return $row['html'] ? $row['value'] : rcube::SQ($row['value']);
        }

        // compose html table
        $table = new html_table(array('cols' => 2));

        foreach ($plugin['output'] as $hkey => $row) {
            $val = $row['html'] ? $row['value'] : rcube::SQ($row['value']);

            $table->add(array('class' => 'header-title'), rcube::SQ($row['title']));
            $table->add(array('class' => 'header '.$hkey), $val);
        }

        return $table->show($attrib);
    }

    /**
     * @param $attrib
     * @param $MESSAGE rcube_message
     * @return string
     */
    private function rcmail_message_body($attrib, $MESSAGE)
    {
        global $OUTPUT, $RCMAIL, $REMOTE_OBJECTS;

        if (!is_array($MESSAGE->parts) && empty($MESSAGE->body)) {
            return '';
        }

        if (!$attrib['id'])
            $attrib['id'] = 'rcmailMsgBody';

        $safe_mode = $MESSAGE->is_safe || intval($_GET['_safe']);
        $out       = '';
        $part_no   = 0;

        $header_attrib = array();
        foreach ($attrib as $attr => $value) {
            if (preg_match('/^headertable([a-z]+)$/i', $attr, $regs)) {
                $header_attrib[$regs[1]] = $value;
            }
        }

        if (!empty($MESSAGE->parts)) {
            foreach ($MESSAGE->parts as $part) {
                if ($part->type == 'headers') {
                    $out .= html::div('message-partheaders', rcmail_message_headers(count($header_attrib) ? $header_attrib : null, $part->headers));
                }
                else if ($part->type == 'content') {
                    // unsupported (e.g. encrypted)
                    if ($part->realtype) {
                        if ($part->realtype == 'multipart/encrypted' || $part->realtype == 'application/pkcs7-mime') {
                            if (!empty($_SESSION['browser_caps']['pgpmime']) && ($pgp_mime_part = $MESSAGE->get_multipart_encrypted_part())) {
                                $out .= html::span('part-notice', $RCMAIL->gettext('externalmessagedecryption'));
                                $OUTPUT->set_env('pgp_mime_part', $pgp_mime_part->mime_id);
                                $OUTPUT->set_env('pgp_mime_container', '#' . $attrib['id']);
                                $OUTPUT->add_label('loadingdata');
                            }

                            if (!$MESSAGE->encrypted_part) {
                                $out .= html::span('part-notice', $RCMAIL->gettext('encryptedmessage'));
                            }
                        }
                        continue;
                    }
                    else if (!$part->size) {
                        continue;
                    }
                    // Check if we have enough memory to handle the message in it
                    // #1487424: we need up to 10x more memory than the body
                    else if (!rcube_utils::mem_check($part->size * 10)) {
                        $out .= rcmail_part_too_big_message($MESSAGE, $part->mime_id);
                        continue;
                    }

                    // fetch part body
                    $body = $MESSAGE->get_part_body($part->mime_id, true);

                    // message is cached but not exists (#1485443), or other error
                    if ($body === false) {
                        rcmail_message_error($MESSAGE->uid);
                    }

//                    $plugin = $RCMAIL->plugins->exec_hook('message_body_prefix',
//                        array('part' => $part, 'prefix' => '', 'message' => $MESSAGE));

                    // Set attributes of the part container
                    $container_class  = $part->ctype_secondary == 'html' ? 'message-htmlpart' : 'message-part';
                    $container_id     = $container_class . (++$part_no);
                    $container_attrib = array('class' => $container_class, 'id' => $container_id);

                    $body_args = array(
                        'safe'         => $safe_mode,
                        'plain'        => !$RCMAIL->config->get('prefer_html'),
                        'css_prefix'   => 'v' . $part_no,
                        'body_class'   => 'rcmBody',
                        'container_id'     => $container_id,
                        'container_attrib' => $container_attrib,
                    );

                    // Parse the part content for display
//                    $body = rcmail_print_body($body, $part, $body_args);
                    $body = $this->rcmail_print_body($body, $part, $body_args);

                    // check if the message body is PGP encrypted
                    if (strpos($body, '-----BEGIN PGP MESSAGE-----') !== false) {
                        $OUTPUT->set_env('is_pgp_content', '#' . $container_id);
                    }

                    if ($part->ctype_secondary == 'html') {
                        $body = rcmail_html4inline($body, $body_args);
                    }

//                    $out .= html::div($body_args['container_attrib'], $plugin['prefix'] . $body);
                    $out .= html::div($body_args['container_attrib'], $body);
                }
            }
        }
        else {
            // Check if we have enough memory to handle the message in it
            // #1487424: we need up to 10x more memory than the body
            if (!rcube_utils::mem_check(strlen($MESSAGE->body) * 10)) {
                $out .= rcmail_part_too_big_message($MESSAGE, 0);
            }
            else {
//                $plugin = $RCMAIL->plugins->exec_hook('message_body_prefix',
//                    array('part' => $MESSAGE, 'prefix' => ''));

//                $out .= html::div('message-part',
//                    $plugin['prefix'] . rcmail_plain_body($MESSAGE->body));
                $out .= html::div('message-part', rcmail_plain_body($MESSAGE->body));
            }
        }

        // list images after mail body
        if ($RCMAIL->config->get('inline_images', true) && !empty($MESSAGE->attachments)) {
            $thumbnail_size   = $RCMAIL->config->get('image_thumbnail_size', 240);
            $client_mimetypes = (array)$RCMAIL->config->get('client_mimetypes');

            $show_label     = rcube::Q($RCMAIL->gettext('showattachment'));
            $download_label = rcube::Q($RCMAIL->gettext('download'));

            foreach ($MESSAGE->attachments as $attach_prop) {
                // skip inline images
                if ($attach_prop->content_id && $attach_prop->disposition == 'inline') {
                    continue;
                }

                // Content-Type: image/*...
                if ($mimetype = rcmail_part_image_type($attach_prop)) {
                    // display thumbnails
                    if ($thumbnail_size) {
                        $supported = in_array($mimetype, $client_mimetypes);

                        $show_link_attr = array(
//                            'href'    => $MESSAGE->get_part_url($attach_prop->mime_id, false),
                            'href' => "?_task=mail&_frame=1&_mbox={$MESSAGE->folder}&_uid={$MESSAGE->uid}&_part={$attach_prop->mime_id}&_action=get&_extwin=1",
                            'target' => '_blank',
//                            'onclick' => sprintf(
//                                'return %s.command(\'load-attachment\',\'%s\',this)',
//                                rcmail_output::JS_OBJECT_NAME,
//                                $attach_prop->mime_id
//                            )
                        );
                        $download_link_attr = array(
//                            'href'  => $show_link_attr['href'] . '&_download=1',
                            'href'  => $MESSAGE->get_part_url($attach_prop->mime_id, false) . '&_download=1',
                            'target' => '_blank'
                        );

                        $show_link     = html::a($show_link_attr + array('class' => 'open'), $show_label);
                        $download_link = html::a($download_link_attr + array('class' => 'download'), $download_label);

                        $out .= html::p(array('class' => 'image-attachment', 'style' => $supported ? '' : 'display:none'),
                            html::a($show_link_attr + array('class' => 'image-link', 'style' => sprintf('width:%dpx', $thumbnail_size)),
                                html::img(array(
                                    'class' => 'image-thumbnail',
                                    'src'   => $MESSAGE->get_part_url($attach_prop->mime_id, 'image') . '&_thumb=1',
                                    'title' => $attach_prop->filename,
                                    'alt'   => $attach_prop->filename,
                                    'style' => sprintf('max-width:%dpx; max-height:%dpx', $thumbnail_size, $thumbnail_size),
                                    'onload' => $supported ? '' : '$(this).parents(\'p.image-attachment\').show()',
                                ))
                            ) .
                            html::span('image-filename', rcube::Q($attach_prop->filename)) .
                            html::span('image-filesize', rcube::Q($RCMAIL->message_part_size($attach_prop))) .
                            html::span('attachment-links', ($supported ? $show_link . '&nbsp;' : '') . $download_link) .
                            html::br(array('style' => 'clear:both'))
                        );
                    }
                    else {
                        $out .= html::tag('fieldset', 'image-attachment',
                            html::tag('legend', 'image-filename', rcube::Q($attach_prop->filename)) .
                            html::p(array('align' => 'center'),
                                html::img(array(
                                    'src'   => $MESSAGE->get_part_url($attach_prop->mime_id, 'image'),
                                    'title' => $attach_prop->filename,
                                    'alt'   => $attach_prop->filename,
                                )))
                        );
                    }
                }
            }
        }

        // tell client that there are blocked remote objects
        if ($REMOTE_OBJECTS && !$safe_mode) {
            $OUTPUT->set_env('blockedobjects', true);
        }

        return html::div($attrib, $out);
    }

    private function rcmail_print_body($body, $part, $p = array())
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

    private function rcmail_message_summary($attrib, $MESSAGE, $header)
    {
        global $RCMAIL;

        if (!isset($MESSAGE) || empty($MESSAGE->headers)) {
            return '';
        }
//        $header = $MESSAGE->context ? 'from' : rcmail_message_list_smart_column_name();
        $label  = 'shortheader' . $header;
        $date   = $RCMAIL->format_date($MESSAGE->headers->date, $RCMAIL->config->get('date_long', 'x'));
        $user   = $MESSAGE->headers->$header;

        if (!$user && $header == 'to') {
            $user = $MESSAGE->headers->cc;
        }
        if (!$user && $header == 'to') {
            $user = $MESSAGE->headers->bcc;
        }

        $vars[$header] = rcmail_address_string($user, 1, true, $attrib['addicon'], $MESSAGE->headers->charset);
        $vars['date']  = html::span('text-nowrap', $date);

        if (empty($user)) {
            $label = 'shortheaderdate';
        }

        $out = html::span(null, $RCMAIL->gettext(array('name' => $label, 'vars' => $vars)));

        return html::div($attrib, $out);
    }

    private function rcmail_message_contactphoto($attrib, $MESSAGE)
    {
        global $RCMAIL;

        $placeholder = $attrib['placeholder'] ? $RCMAIL->output->abs_url($attrib['placeholder'], true) : null;
        $placeholder = $RCMAIL->output->asset_url($placeholder ?: 'program/resources/blank.gif');

        if ($MESSAGE->sender) {
            $photo_img = $RCMAIL->url(array(
                '_task'   => 'addressbook',
                '_action' => 'photo',
                '_email'  => $MESSAGE->sender['mailto'],
                '_error'  => strpos($placeholder, 'blank.gif') === false ? 1 : null,
            ));

            $attrib['onerror'] = "this.src = '$placeholder'; this.onerror = null";
        }
        else {
            $photo_img = $placeholder;
        }

        return html::img(array('src' => $photo_img, 'alt' => $RCMAIL->gettext('contactphoto')) + $attrib);
    }

    /**
     * @param $args array('type' => $part->ctype_secondary, 'body' => $body, 'id' => $part->mime_id)
     * @return mixed
     */
    function message_part_after($args)
    {
        global $MESSAGE;

        $part_type = $args['type'];
        if ($part_type != 'html') {
            return $args['body'];
        }

        if ($MESSAGE->folder == $this->sentFolder || $MESSAGE->folder == $this->draftFolder) {
            return $args['body'];
        }

        if (strpos($args['body'], '<body') === false) {
            $args['body'] = "<body>{$args['body']}</body>";
        }

        $message_id = $MESSAGE->get_header('Message-Id');
        /** @var rcube_imap_generic $connection */
        $connection = $this->rcmail->storage->conn;

        $receive_thread = $connection->thread($MESSAGE->folder, 'REFERENCES',
            "HEADER References {$message_id}", true);

        $sent_thread = $connection->thread($this->sentFolder, 'REFERENCES',
            "HEADER References {$message_id}", true);


        $receive_uids = $receive_thread->get();
        $receive_messages = [];
        foreach($receive_uids as $uid) {
            $receive_messages[] = new rcube_message($uid, $MESSAGE->folder);
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

        $part_index = 0;
        foreach($messages as $message) {
            if (empty($message->headers->flags['SEEN']) && $message->context === null) {
                $this->rcmail->output->command('set_unread_message', $message->uid, $message->folder);
            }

            $args['body'] .= '<hr style="border: 1px solid red;clear:both;" />';
//            $args['body'] .= $this->rcmail_message_contactphoto(array('name' => "messageChatPhoto{$part_index}",
//                'id' => "messageChatPhoto{$part_index}"), $message);

            $args['body'] .= $this->rcmail_message_summary(array('name' => "messageChatSummary{$part_index}",
                'id' => "messageChatSummary{$part_index}", 'data-message-uid' => $message->uid,
                'class' => 'thread-chat-summary'), $message,
                $message->folder == $this->sentFolder ? 'to' : 'from');

//            $args['body'] .= $this->rcmail_message_headers(array('name' => "messageChatHeaders{$part_index}",
//                'id' => "messageChatHeaders{$part_index}"), $message);

            $args['body'] .= $this->rcmail_message_body(array('name' => "messageChatBody{$part_index}",
                'id' => "messageChatBody{$part_index}", 'class' => 'thread-chat-body',
                'data-message-uid' => $message->uid), $message);

            $args['body'] .= <<<EOT
                <div class="thread-button-block" data-message-uid="{$message->uid}">
                <br style="clear: both;" />
                <button onclick="parent.document.location='?_task=mail&_reply_uid={$message->uid}&_mbox={$message->folder}&_action=compose'">Reply</button>
                <button onclick="parent.document.location='?_task=mail&_reply_uid={$message->uid}&_mbox={$message->folder}&_all=all&_action=compose'">Reply all</button>
                <button onclick="parent.document.location='?_task=mail&_forward_uid={$message->uid}&_mbox={$message->folder}&_action=compose'">Forward</button>
                </div>
EOT;

            $part_index += 1;
        }

        if (!empty($messages)) {
            $args['body'] .= '<div style="height: 10px;"></div>';

            $arr_prefs = $this->rcmail->user->get_prefs();
            if (isset($arr_prefs['rcube_threadchats']) && $arr_prefs['rcube_threadchats']['_collapse_history']) {
                $args['body'] .= <<<EOT
<script type="text/javascript">
    var styleUidBlock = function(uid, className, styleName, styleValue) {
      var elements = document.getElementsByClassName(className);
      for (var i=0;i<elements.length;i++) {
          if (elements[i].getAttribute('data-message-uid') === uid) {
              elements[i].style[styleName] = styleValue;
              break;
          }
      }
    };
    
    var showOriginalMessage = function(event) {
        event.currentTarget.style.display = 'none';
        var messageUid = event.currentTarget.getAttribute('data-message-uid');
        if (!messageUid) {
            return;
        }
        
        styleUidBlock(messageUid, 'message-previewed', 'display', 'block');
        styleUidBlock(messageUid, 'thread-button-block', 'display', 'block');
        
        styleUidBlock(messageUid, 'thread-chat-summary', 'background', '#cccccc');
        styleUidBlock(messageUid, 'thread-chat-summary', 'cursor', 'pointer');
    };
    
    var addMessageCollapser = function(el) {
        // var textContent = el.textContent.replace(/\s\s+/g, ' ').replace(/(<([^>]+)>)/ig,"").trim();
        // var textContent = el.textContent.replace(/\s+/g, ' ').replace(/(<([^>]+)>)/ig,"").trim();
        var textContent = el.innerText.replace(/\s+/g, ' ').replace(/(<([^>]+)>)/ig,"").trim();
        if (textContent.length > 100) {
            textContent = textContent.substring(0, 100) + '...';
        }
        var div = document.createElement('div');
        div.classList.add('message-preview');
        div.innerText = textContent;
        div.style.background = '#cccccc';
        div.style.cursor = 'pointer';
        el.parentNode.insertBefore(div, el);
        el.style.display = 'none';
        el.classList.add('message-previewed');
        
        var messageUid = el.getAttribute('data-message-uid');
        if (messageUid) {
            div.setAttribute('data-message-uid', messageUid);
            styleUidBlock(messageUid, 'thread-button-block', 'display', 'none');
        }
        
        div.addEventListener('click', showOriginalMessage);
    };
   
    var mainMessageBody = document.getElementsByClassName('rcmBody')[0];
    mainMessageBody.classList.add('thread-chat-body');
    mainMessageBody.setAttribute('data-message-uid', '{$MESSAGE->uid}');
    // addMessageCollapser(mainMessageBody);
    
    var chatBodies = document.getElementsByClassName('thread-chat-body');
    for (var i=0;i<chatBodies.length - 1;i++) {
        addMessageCollapser(chatBodies[i]);
    }
    
    var summaryClick = function(event) {
        var messageUid = event.currentTarget.getAttribute('data-message-uid');
        if (!messageUid) {
            return;
        }
        
        if (event.currentTarget.style.cursor === 'pointer') {
            styleUidBlock(messageUid, 'thread-button-block', 'display', 'none');
            styleUidBlock(messageUid, 'thread-chat-body', 'display', 'none');
            styleUidBlock(messageUid, 'message-preview', 'display', 'block');
            event.currentTarget.style.cursor = 'auto';
            event.currentTarget.style.background = 'none';
        }
    };
    
    var mainMessageHeader = document.getElementById('message-header');
    mainMessageHeader.addEventListener('click', summaryClick);
    mainMessageHeader.classList.add('thread-chat-summary');
    mainMessageHeader.setAttribute('data-message-uid', '{$MESSAGE->uid}');
    
    var chatSummary = document.getElementsByClassName('thread-chat-summary');
    for (var i=0;i<chatSummary.length;i++) {
        chatSummary[i].addEventListener('click', summaryClick);
    }
    
</script>
EOT;

            }
        }



        //rcmBody

        return $args;
    }
}