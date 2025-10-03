<?php declare(strict_types=1);

/**
 * Project:   On-Time Shipping Method for Picqer
 * File:      picqer-ontime.php [test=false|true] [method=process|producten|landen]
 * @author    Jeroen de Jong <jeroen@telartis.nl>
 * @copyright 2022 Telartis BV
 * @link      https://picqer.com/en/api/custom-shippingmethod
 * @link      https://api.asx.be/?pag=distri&sub=create
 *
 * Usage:
 * $po = new \telartis\picqer_ontime\picqer_ontime();
 * $po->auth_user = '';
 * $po->auth_pw = '';
 * $po->ontime = [...];
 * $po->main();
 *
 * Functions:
 * main(): void
 * process(): array
 * producten(): array
 * landen(): array
 * create(array $data_in): array
 * verwerking(string $name): array
 * contact($name, $contact, $address, $address2, $zipcode, $city, $country, $tel): array
 * query(array $data, string $result_key): array
 * remarks(array $result): string
 * error(string $error): array
 * dbg($data, string $name = ''): string
 * basic_http_auth(): array
 * output(array $response): void
 * json_encode(array $data, int $flags = JSON_PRETTY_PRINT): string
 * clean_html(string $html): string
 * split_address($address): array
 * format_country(string $country): string
 * format_telephone(string $tel): string
 * product_name(int $grams): string
 * trim($value): string
 * hide_pass(string $text): string
 *
 */

namespace telartis\picqer_ontime;

class picqer_ontime
{
    public string $auth_user = '';
    public string $auth_pw   = '';

    public string $log_file = '/var/log/picqer-ontime.log'; // leave empty if you do not want logging

    /*

    quick read logfile: egrep ^[0-9]{4}- /var/log/picqer-ontime.log

    sudo vim /etc/logrotate.d/picqer-ontime
    /var/log/picqer-ontime.log {
        monthly
        dateext
        dateformat -%Y-%m
        dateyesterday
        create 0664 www-data www-data
        rotate 24
        compress
    }

    */

    public array $ontime = [
        'apiurl'    => 'https://api.asx.be/DISTRI/',
        'gebruiker' => 'user@example.com',
        'klantnr'   => '12345',
        'apipswd'   => '',
        'sender_emailaddress' => 'user@example.com',
        'sender_telephone'    => '003212345678',
        'producten' => [
             60 => 'COLLI 30-60kg - 0.4 - 0 - 60',
             90 => 'minipallet 60-90 kg - 0.6 - 0 - 90',
            300 => 'PALLET >90 kg - 1 - 0 - 300',
        ],
    ];

    public int $http_code = 200; // OK

    public array $http_headers = [
        'Cache-Control: no-cache',
        'Content-type: application/json',
    ];

    public bool $is_test  = false;
    public array $data_in  = [];
    public array $data_out = [];
    public array $json_in  = [];
    public array $json_out = [];


    /**
     * Main function
     *
     * @return void
     */
    public function main(): void
    {
        $is_test = (bool)   filter_input(INPUT_GET, 'test',   FILTER_VALIDATE_BOOLEAN);
        $method  = (string) filter_input(INPUT_GET, 'method', FILTER_DEFAULT, FILTER_FLAG_STRIP_LOW | FILTER_FLAG_STRIP_HIGH | FILTER_FLAG_STRIP_BACKTICK);

        if (!in_array($method, ['process', 'producten', 'landen'])) {
            $method = 'process';
        }

        $this->is_test = $is_test;

        $response = $this->$method();

        $debug = $this->hide_pass(
            $this->dbg($this->json_in,  'json_in' )."\n\n".
            $this->dbg($this->json_out, 'json_out')."\n\n".
            $this->dbg($this->data_in,  'data_in' )."\n\n".
            $this->dbg($this->data_out, 'data_out')."\n\n".
            ''
        );

        if (isset($response['error'])) {
            $response['error'] = $response['error']."\n\n".$debug;
            $details = $response['error'];
            $message = substr($details, 0, strpos($details, "\n")); // only get first line of error message
        } else {
            $details = $this->dbg($response, 'response')."\n\n".$debug;
            $message = $response['identifier'];
        }

        if (!empty($this->log_file)) {
            @file_put_contents(
                $this->log_file,
                str_repeat('=', 80)."\n".implode("\t", [
                    date('Y-m-d H:i:s'),
                    $_SERVER['REMOTE_ADDR'],
                    $this->http_code,
                    $message
                ])."\n".$details,
                FILE_APPEND
            );
        }

        $this->output($response);
    }

    /**
     * Get Picqer data and process the data
     *
     * @return array
     */
    public function process(): array
    {
        $response = $this->basic_http_auth();
        if ($this->http_code != 200) {

            return $response;
        }

        // Read raw data from the request body:
        $json = file_get_contents('php://input');
        $this->data_in = json_decode($json, true);

        $error = '';
        if (!is_array($this->data_in)) {
            $error = 'Input error! '.$this->dbg($this->data_in);
        } else {
            $response = $this->create($this->data_in);
            if (isset($response['error'])) {
                $error = $response['error'];
            } else {
                $count = isset($response['opdrachten']) ? count($response['opdrachten']) : 0;
                if ($count != 1) {
                    $error = "Error $count orders found!\n".(isset($response['remarks']) ? $response['remarks'] : '');
                } else {
                    $opdracht = $response['opdrachten'][0];

                    $identifier  = isset($opdracht['nummer']) ? (string) $opdracht['nummer'] : '';  // xxx
                    $trackingurl = isset($opdracht['track'])  ? (string) $opdracht['track']  : '';  // https://track.asx.be/xxx
                    $labelurl    = isset($opdracht['label'])  ? (string) $opdracht['label']  : '';  // https://portal.asx.be/pdf/bestelling/?etiket=DSTRAPIxxx

                    $label_contents_pdf = base64_encode((string) file_get_contents($labelurl));

                    $response = [
                        'identifier'         => $identifier,         // Shipper's trackingcode
                        'trackingurl'        => $trackingurl,        // URL of track and trace page
                        'carrier_key'        => 'ontime',            // Key of the carrier, so Picqer can show the right logo
                        'label_contents_pdf' => $label_contents_pdf, // Base64 encoded PDF document of label
                    ];
                }
            }
        }

        if ($error) {
            $response = $this->error($error);
        }

        return $response;
    }

    /**
     * Provide an overview of all possible On-Time products
     *
     * @return array
     */
    public function producten(): array
    {
        $data = $this->verwerking('PRODUCT');

        return $this->query($data, 'producten');
    }

    /**
     * List possible On-Time countries
     *
     * @return array
     */
    public function landen(): array
    {
        $data = $this->verwerking('LANDEN');

        return $this->query($data, 'landen');
    }

    /**
     * Make On-Time 'create' request and send query
     *
     * @param  array    $data_in
     * @return array    Response from On-Time
     */
    public function create(array $data_in): array
    {
        $verzender = [
            'contact'    => $this->trim($data_in['user']['firstname'].' '.$data_in['user']['lastname']),
            'email'      => $this->trim($this->ontime['sender_emailaddress']),
            'referentie' => $this->trim($data_in['reference']),
            'tel'        => $this->format_telephone($this->ontime['sender_telephone']),
        ];

        $ophalen = $this->contact(
            $data_in['sender']['name'],
            $data_in['sender']['contactname'],
            $data_in['sender']['address'],
            $data_in['sender']['address2'],
            $data_in['sender']['zipcode'],
            $data_in['sender']['city'],
            $data_in['sender']['country'],
            $this->ontime['sender_telephone']
        );

        $leveren = $this->contact(
            $data_in['picklist']['deliveryname'],
            $data_in['picklist']['deliverycontact'],
            $data_in['picklist']['deliveryaddress'],
            $data_in['picklist']['deliveryaddress2'],
            $data_in['picklist']['deliveryzipcode'],
            $data_in['picklist']['deliverycity'],
            $data_in['picklist']['deliverycountry'],
            $data_in['picklist']['telephone']
        );
        $leveren['track_email'] = $this->trim($data_in['picklist']['emailaddress']);

        $goederen = [];
        $goederen[] = ['goed' => [
            'product' => $this->product_name($data_in['weight']),
            'aantal'  => 1,
            'gewicht' => $data_in['weight'] / 1000,
        ]];

        $this->data_out = array_merge($this->verwerking('CREATE'), [
            'verzender'  => $verzender,
            'opdracht'   => ['neutraal' => 0],
            'ophalen'    => $ophalen,
            'leveren'    => $leveren,
            'goederen'   => $goederen,
        ]);

        return $this->query($this->data_out, 'opdrachten');
    }

    /**
     * Get On-Time verwerking array
     *
     * @param  string   $name
     * @return array
     */
    public function verwerking(string $name): array
    {
        return [
            'verwerking' => $name,
            'gebruiker'  => $this->ontime['gebruiker'],
            'klantnr'    => $this->ontime['klantnr'],
            'apipswd'    => $this->ontime['apipswd'],
            'omgeving'   => $this->is_test ? 'TEST' : 'LIVE',
        ];
    }

    /**
     * Get On-Time contact array
     *
     * @param  mixed    $name
     * @param  mixed    $contact
     * @param  mixed    $address
     * @param  mixed    $address2
     * @param  mixed    $zipcode
     * @param  mixed    $city
     * @param  mixed    $country
     * @param  mixed    $tel
     * @return array
     */
    public function contact($name, $contact, $address, $address2, $zipcode, $city, $country, $tel): array
    {
        [$street, $number] = $this->split_address($address);

        return [
            'bedrijf'  => $this->trim($name),
            'contact'  => $this->trim($contact),
            'straat'   => $street,
            'huisnr'   => $number,
            'adres2'   => $this->trim($address2),
            'postcode' => $this->trim($zipcode),
            'gemeente' => $this->trim($city),
            'land    ' => $this->format_country($this->trim($country)),
            'tel'      => $this->format_telephone($this->trim($tel)),
        ];
    }

    /**
     * Send query request to On-Time API endpoint
     *
     * @param  array    $data
     * @param  string   $result_key  'opdrachten', 'producten', 'landen'
     * @return array(remarks, $result_key) or array(error)
     */
    public function query(array $data, string $result_key): array
    {
        $error = '';

        $ch = curl_init($this->ontime['apiurl']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT,
            'Mozilla/4.0 (compatible; Telartis/Picqer API Client'.
            '; '.php_uname('s').
            '; PHP/'.phpversion().')'
        );
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');

        $this->json_in = json_encode($data, JSON_PRETTY_PRINT);
        if ($this->json_in === false) {
            // json_input error
            $error = 'ERROR JSON input! '.json_last_error_msg().
                "\n\n".$this->dbg($data);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $this->json_in);
            $this->json_out = curl_exec($ch);
            $info = (object) curl_getinfo($ch);
            $this->http_code = (int) $info->http_code;
            if ($this->json_out === false) {
                // curl_exec error
                $error = 'ERROR ontime curl exec! '.curl_error($ch).' ('.curl_errno($ch).')'.
                    "\n\n".$this->hide_pass($this->json_in).
                    "\n\n".$this->dbg($info);
            } else {
                $result = json_decode($this->json_out, true);
                if (!is_array($result)) {
                    // json result is not an array
                    if (is_string($this->json_out)) {
                        $msg = $this->clean_html($this->json_out);
                        if (empty($msg)) {
                            $msg = 'empty response.';
                        }
                    } else {
                        $msg = $this->dbg($this->json_out);
                    }
                    $error = 'ERROR ontime result! '.$msg.
                        "\n\n".$this->hide_pass($this->json_in);
                } elseif (!isset($result['status'])) {
                    // json result does not have status-key
                    $error = 'ERROR ontime result does not have a status-key! '.$this->dbg($result);
                } elseif ($result['status'] != 'SUCCESS') {
                    // ERROR
                    $error = $result['status'].' '.$this->remarks($result);
                } else {
                    // SUCCESS
                    if (!isset($result[$result_key])) {
                        $error = "ERROR ontime result does not have an $result_key-key! ".$this->dbg($result).
                            "\n\n".$this->remarks($result);
                    } else {
                        // OK
                    }
                }
            }
        }

        if ($error && $this->http_code == 200) {
            $this->http_code = 400;
        }

        if ($error) {
            $response = ['error' => $error];
        } else {
            $response = ['remarks' => $this->remarks($result)];
            $rows = [];
            foreach ($result[$result_key] as $row) {
                $key = substr($result_key, 0, -2);
                if ($key == 'opdracht') {
                    $rows[] = $row[$key];
                } else {
                    $rows[] = $row;
                }
            }
            $response[$result_key] = $rows;
        }

        return $response;
    }

    /**
     * Get remarks from On-Time result array
     *
     * @param  array    $result
     * @return string
     */
    public function remarks(array $result): string
    {
        $string = '';
        if (!isset($result['remarks'])) {
            $string = 'result[remarks] key not set! '.$this->dbg($result);
        } elseif (!is_array($result['remarks'])) {
            $string = 'result[remarks] is not an array! '.$this->dbg($result);
        } else {
            $remarks = [];
            foreach ($result['remarks'] as $row) {
                $remarks[] = $row['remark'];
            }
            $string = implode("\n", $remarks);
        }

        return $string;
    }

    /**
     * Return error response and set HTTP-code
     *
     * @param  string   $error
     * @return array
     */
    public function error(string $error): array
    {
        // Only change http status code if it currently is 200 OK, do not touch existing error codes.
        if ($this->http_code == 200) {
            $this->http_code = 400; // Bad Request
        }

        return ['error' => $error];
    }

    /**
     * Debug Variable
     *
     * @param  mixed    $data  Variable to output in debug string
     * @param  string   $name  Name of variable
     * @return string
     */
    public function dbg($data, string $name = ''): string
    {
        ob_start();
        var_dump($data);
        $result = trim(ob_get_contents());
        ob_end_clean();
        if (!empty($name)) {
            $result = "$name=[$result]";
        }

        return $this->hide_pass($result);
    }

    /**
     * HTTP Basic authentication
     *
     * @return array  - Empty array on Success and [error => 'message'] on Failure
     */
    public function basic_http_auth(): array
    {
        if (($_SERVER['PHP_AUTH_USER'] == $this->auth_user) && ($_SERVER['PHP_AUTH_PW'] == $this->auth_pw)) {

            return [];
        } else {
            $this->http_code = 401; // Unauthorized
            $this->http_headers[] = 'WWW-Authenticate: Basic realm="picqer-ontime"';

            return ['error' => 'Not authorized!'];
        }
    }

    /**
     * Echo JSON and set HTTP-code and HTTP-headers
     *
     * @param  array    $response
     * @return void
     */
    public function output(array $response): void
    {
        http_response_code($this->http_code);

        foreach ($this->http_headers as $http_header) {
            header($http_header);
        }

        echo $this->json_encode($response);
    }

    /**
     * JSON encode and set HTTP-code on error
     *
     * @param  array    $data
     * @param  integer  $flags  Optional, default JSON_PRETTY_PRINT
     * @return string
     */
    public function json_encode(array $data, int $flags = JSON_PRETTY_PRINT): string
    {
        $json = json_encode($data, $flags);
        if ($json === false) {
            $data = $this->error(json_last_error_msg());
            $json = json_encode($data, $flags);
        }

        return $json;
    }

    /**
     * Clean HTML
     *
     * @param  string   $html
     * @return string
     */
    public function clean_html(string $html): string
    {
        // get contents of body-tag from HTML and strip tags
        $string = strip_tags(preg_replace('|^.*<body[^>]*>|ims', '', preg_replace('|</body>.*$|ims', '', $html)));

        // &nbsp; -> normal space
        $string = str_replace('&nbsp;', ' ', $string);

        // replace non-breaking space characters with normal spaces
        $string = str_replace("\xc2\xa0", ' ', $string);

        // remove multiple spaces
        $string = preg_replace('/ +/', ' ', $string);

        // remove spaces at begin of lines
        $string = preg_replace('/^ +/m', '', $string);

        // remove spaces at end of lines
        $string = preg_replace('/ +$/m', '', $string);

        // replace three or more line feeds with just two line feeds
        $string = preg_replace('/\n{3,}/', "\n\n", str_replace("\r", "", $string));

        return trim($string);
    }

    /**
     * Split address into street and number parts
     *
     * @param  mixed    $address        'Kattendijkdok 5A', '5A, Kattendijkdok'
     * @return array($street, $number)  ['Kattendijkdok', '5A']
     */
    public function split_address($address): array
    {
        $street = $this->trim($address);
        $number = '';
        if (preg_match('/^(\D+)\s+(\d+.*)$/', $street, $match)) {
            $street = $match[1];
            $number = $match[2];
        } elseif (preg_match('/^(\d+\S*)\s+(.*)$/', $street, $match)) {
            $number = $match[1];
            $street = $match[2];
        }
        $characters = " \n\r\t\v\x00,"; // also trim comma
        $street = trim($street, $characters);
        $number = trim($number, $characters);

        return [$street, $number];
    }

    /**
     * Change full country name into alpha-2 country code
     *
     * @param  string   $country  'Belgium'
     * @return string   'BE'
     */
    public function format_country(string $country): string
    {
        $countries = [
            'België'          => 'BE',
            'Belgie'          => 'BE',
            'Belgium'         => 'BE',
            'Lëtzebuerg'      => 'LU',
            'Luxemburg'       => 'LU',
            'Luxembourg'      => 'LU',
            'Nederland'       => 'NL',
            'Netherlands'     => 'NL',
            'The Netherlands' => 'NL',
        ];

        return str_ireplace(array_keys($countries), array_values($countries), trim($country));
    }

    /**
     * Format telephone number with only digits, replace plus (+) with 00
     *
     * @param  string   $tel
     * @return string
     */
    public function format_telephone(string $tel): string
    {
        return preg_replace('/\D/', '', str_replace('+', '00', $tel));
    }

    /**
     * Get On-Time product name based on the weight in grams
     *
     * @param  integer  $grams
     * @return string
     */
    public function product_name(int $grams): string
    {
        $kilo = (int) round($grams / 1000);

        $result = '';
        foreach ($this->ontime['producten'] as $limit => $name) {
            if ($kilo <= $limit) {
                $result = $name;
                break;
            }
        }

        return $result;
    }

    /**
     * Trim and remove NULL values
     *
     * @param  mixed    $value
     * @return string
     */
    public function trim($value): string
    {
        return is_null($value) ? '' : trim($value);
    }

    /**
     * Remove API password from text
     *
     * @param  string   $text
     * @return string
     */
    public function hide_pass(string $text): string
    {
        return str_replace($this->ontime['apipswd'], '***', $text);
    }

} // end class
