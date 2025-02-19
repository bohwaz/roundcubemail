<?php

/*
 +-------------------------------------------------------------------------+
 | Mail_mime wrapper for the Enigma Plugin                                 |
 |                                                                         |
 | Copyright (C) The Roundcube Dev Team                                    |
 |                                                                         |
 | Licensed under the GNU General Public License version 3 or              |
 | any later version with exceptions for skins & plugins.                  |
 | See the README file for a full license statement.                       |
 +-------------------------------------------------------------------------+
 | Author: Aleksander Machniak <alec@alec.pl>                              |
 +-------------------------------------------------------------------------+
*/

class enigma_mime_message extends Mail_mime
{
    public const PGP_SIGNED    = 1;
    public const PGP_ENCRYPTED = 2;

    protected $type;
    protected $message;
    protected $body;
    protected $signature;
    protected $encrypted;
    protected $micalg;

    /**
     * Object constructor
     *
     * @param Mail_mime $message Original message
     * @param int       $type    Output message type
     */
    public function __construct($message, $type)
    {
        $this->message = $message;
        $this->type    = $type;

        // clone parameters
        foreach (array_keys($this->build_params) as $param) {
            $this->build_params[$param] = $message->getParam($param);
        }

        // clone headers
        $this->headers = $message->headers();

        // \r\n is must-have here
        $this->body = $message->get() . "\r\n";
    }

    /**
     * Check if the message is multipart (requires PGP/MIME)
     *
     * @return bool True if it is multipart, otherwise False
     */
    public function isMultipart()
    {
        return $this->message instanceof self
            || $this->message->isMultipart() || $this->message->getHTMLBody();
    }

    /**
     * Get e-mail address of message sender
     *
     * @return string|null Sender address
     */
    public function getFromAddress()
    {
        // get sender address
        $headers = $this->message->headers();

        if (isset($headers['From'])) {
            $from    = rcube_mime::decode_address_list($headers['From'], 1, false, null, true);
            $from    = $from[1] ?? null;

            return $from;
        }

        return null;
    }

    /**
     * Get recipients' e-mail addresses
     *
     * @return array Recipients' addresses
     */
    public function getRecipients()
    {
        // get sender address
        $headers = $this->message->headers();
        $to      = rcube_mime::decode_address_list($headers['To'] ?? '', null, false, null, true);
        $cc      = rcube_mime::decode_address_list($headers['Cc'] ?? '', null, false, null, true);
        $bcc     = rcube_mime::decode_address_list($headers['Bcc'] ?? '', null, false, null, true);

        $recipients = array_unique(array_filter(array_merge($to, $cc, $bcc)));
        $recipients = array_diff($recipients, ['undisclosed-recipients:']);

        return array_values($recipients);
    }

    /**
     * Get original message body, to be encrypted/signed
     *
     * @return string Message body
     */
    public function getOrigBody()
    {
        $_headers = $this->message->headers();
        $headers  = [];

        if (!empty($_headers['Content-Transfer-Encoding'])
            && stripos($_headers['Content-Type'], 'multipart') === false
        ) {
            $headers[] = 'Content-Transfer-Encoding: ' . $_headers['Content-Transfer-Encoding'];
        }
        $headers[] = 'Content-Type: ' . $_headers['Content-Type'];

        return implode("\r\n", $headers) . "\r\n\r\n" . $this->body;
    }

    /**
     * Register signature attachment
     *
     * @param string $body      Signature body
     * @param string $algorithm Hash algorithm name
     */
    public function addPGPSignature($body, $algorithm = null)
    {
        $this->signature = $body;
        $this->micalg    = $algorithm;

        // Reset Content-Type to be overwritten with valid boundary
        unset($this->headers['Content-Type']);
        unset($this->headers['Content-Transfer-Encoding']);
    }

    /**
     * Register encrypted body
     *
     * @param string $body Encrypted body
     */
    public function setPGPEncryptedBody($body)
    {
        $this->encrypted = $body;

        // Reset Content-Type to be overwritten with valid boundary
        unset($this->headers['Content-Type']);
        unset($this->headers['Content-Transfer-Encoding']);
    }

    /**
     * Builds the multipart message.
     *
     * @param array    $params    Build parameters that change the way the email
     *                            is built. Should be associative. See $_build_params.
     * @param resource $filename  Output file where to save the message instead of
     *                            returning it
     * @param bool     $skip_head True if you want to return/save only the message
     *                            without headers
     *
     * @return mixed The MIME message content string, null or PEAR error object
     */
    public function get($params = null, $filename = null, $skip_head = false)
    {
        if (!empty($params)) {
            foreach ($params as $key => $value) {
                $this->build_params[$key] = $value;
            }
        }

        $this->checkParams();

        if ($this->type == self::PGP_SIGNED) {
            $params = [
                'preamble'     => 'This is an OpenPGP/MIME signed message (RFC 4880 and 3156)',
                'content_type' => 'multipart/signed; protocol="application/pgp-signature"',
                'eol'          => $this->build_params['eol'],
            ];

            if ($this->micalg) {
                $params['content_type'] .= '; micalg=pgp-' . $this->micalg;
            }

            $message = new Mail_mimePart('', $params);

            if (!empty($this->body)) {
                $headers = $this->message->headers();
                $params  = ['content_type' => $headers['Content-Type']];

                if (!empty($headers['Content-Transfer-Encoding'])
                    && stripos($headers['Content-Type'], 'multipart') === false
                ) {
                    $params['encoding'] = $headers['Content-Transfer-Encoding'];

                    // For plain text body we have to decode it back, to prevent from
                    // a double encoding issue (#8413)
                    $this->body = rcube_mime::decode($this->body, $this->build_params['text_encoding']);
                }

                $message->addSubpart($this->body, $params);
            }

            if (!empty($this->signature)) {
                $message->addSubpart($this->signature, [
                    'filename'     => 'signature.asc',
                    'content_type' => 'application/pgp-signature',
                    'disposition'  => 'attachment',
                    'description'  => 'OpenPGP digital signature',
                ]);
            }
        } elseif ($this->type == self::PGP_ENCRYPTED) {
            $params = [
                'preamble'     => 'This is an OpenPGP/MIME encrypted message (RFC 4880 and 3156)',
                'content_type' => 'multipart/encrypted; protocol="application/pgp-encrypted"',
                'eol'          => $this->build_params['eol'],
            ];

            $message = new Mail_mimePart('', $params);

            $message->addSubpart('Version: 1', [
                'content_type' => 'application/pgp-encrypted',
                'description'  => 'PGP/MIME version identification',
            ]);

            $message->addSubpart($this->encrypted, [
                'content_type' => 'application/octet-stream',
                'description'  => 'PGP/MIME encrypted message',
                'disposition'  => 'inline',
                'filename'     => 'encrypted.asc',
            ]);
        }

        // Use saved boundary
        if (!empty($this->build_params['boundary'])) {
            $boundary = $this->build_params['boundary'];
        } else {
            $boundary = null;
        }

        // Write output to file
        if ($filename) {
            // Append mimePart message headers and body into file
            $headers = $message->encodeToFile($filename, $boundary, $skip_head);

            if ($this->isError($headers)) {
                return $headers;
            }

            $this->headers = array_merge($this->headers, $headers);
        } else {
            $output = $message->encode($boundary);

            if ($this->isError($output)) {
                return $output;
            }

            $this->headers = array_merge($this->headers, $output['headers']);
        }

        // remember the boundary used, in case we'd handle headers() call later
        if (empty($boundary) && !empty($this->headers['Content-Type'])) {
            if (preg_match('/boundary="([^"]+)/', $this->headers['Content-Type'], $m)) {
                $this->build_params['boundary'] = $m[1];
            }
        }

        return $filename ? null : $output['body'];
    }

    /**
     * Get Content-Type and Content-Transfer-Encoding headers of the message
     *
     * @return array Headers array
     */
    protected function contentHeaders()
    {
        $this->checkParams();

        $eol = $this->build_params['eol'] ?: "\r\n";

        // multipart message: and boundary
        if (!empty($this->build_params['boundary'])) {
            $boundary = $this->build_params['boundary'];
        } elseif (!empty($this->headers['Content-Type'])
            && preg_match('/boundary="([^"]+)"/', $this->headers['Content-Type'], $m)
        ) {
            $boundary = $m[1];
        } else {
            $boundary = '=_' . md5(rand() . microtime());
        }

        $this->build_params['boundary'] = $boundary;

        if ($this->type == self::PGP_SIGNED) {
            $headers['Content-Type'] = "multipart/signed;{$eol}"
                . " protocol=\"application/pgp-signature\";{$eol}"
                . " boundary=\"{$boundary}\"";

            if ($this->micalg) {
                $headers['Content-Type'] .= ";{$eol} micalg=pgp-" . $this->micalg;
            }
        } elseif ($this->type == self::PGP_ENCRYPTED) {
            $headers['Content-Type'] = "multipart/encrypted;{$eol}"
                . " protocol=\"application/pgp-encrypted\";{$eol}"
                . " boundary=\"{$boundary}\"";
        }

        return $headers;
    }
}
