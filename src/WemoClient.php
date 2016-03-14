<?php
namespace a15lam\PhpWemo;

class WemoClient
{
    const FORMAT_XML = 1;

    const FORMAT_ARRAY = 2;

    const FORMAT_JSON = 3;

    protected $ip = null;

    protected $port = null;

    protected $output = null;

    public function __construct($ip, $port = null)
    {
        $this->ip = $ip;
        $this->port = (!empty($port))? $port : Config::get('port');
        $this->setOutput(static::FORMAT_ARRAY);
    }

    public function setOutput($output)
    {
        $this->output = $output;
    }

    public function info($url)
    {
        $url = 'http://'.$this->ip.'/'.ltrim($url, '/');
        $options = [
            CURLOPT_URL            => $url,
            CURLOPT_PORT           => $this->port,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_VERBOSE        => false
        ];

        $ch = curl_init();
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);

        return $this->formatResponse($response);
    }

    public function request($controlUrl, $service, $method, $arguments = [])
    {
        $controlUrl = ltrim($controlUrl, '/');
        $url = 'http://' . $this->ip . '/' . $controlUrl;
        $action = $service . '#' . $method;

        $xmlHeader = '<?xml version="1.0" encoding="utf-8"?>
                      <s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/" s:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">
                      <s:Body>
                      <u:' . $method . ' xmlns:u="' . $service . '">';

        $xmlFooter = '</u:' . $method . '></s:Body></s:Envelope>';

        $xmlBody = '';

        foreach ($arguments as $key => $value) {
            $xmlBody .= '<' . $key . '>' . $value . '</' . $key . '>';
        }

        $xml = $xmlHeader . $xmlBody . $xmlFooter;

        try {
            $options = [
                CURLOPT_URL            => $url,
                CURLOPT_POST           => true,
                CURLOPT_PORT           => $this->port,
                CURLOPT_POSTFIELDS     => $xml,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_VERBOSE        => false,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type:text/xml',
                    'SOAPACTION:"' . $action . '"'
                ]
            ];

            $ch = curl_init();
            curl_setopt_array($ch, $options);
            $response = curl_exec($ch);
        } catch (\Exception $e){
            throw $e;
        }

        return $this->formatResponse($response);
    }

    protected function formatResponse($response)
    {
        if(static::FORMAT_ARRAY === $this->output){
            $response = static::xmlToArray($response);
        } else if(static::FORMAT_JSON === $this->output){
            $response = static::xmlToArray($response);
            $response = json_encode($response, JSON_UNESCAPED_SLASHES);
        }

        return $response;
    }

    /**
     * xml2array() will convert the given XML text to an array in the XML structure.
     * Link: http://www.bin-co.com/php/scripts/xml2array/
     * Arguments : $contents - The XML text
     *             $get_attributes - 1 or 0. If this is 1 the function will
     *                               get the attributes as well as the tag values
     *                               - this results in a different array structure in the return value.
     *             $priority - Can be 'tag' or 'attribute'. This will change the way the resulting array structure.
     *                         For 'tag', the tags are given more importance.
     * Return: The parsed XML in an array form. Use print_r() to see the resulting array structure.
     * Examples: $array =  xml2array(file_get_contents('feed.xml'));
     *           $array =  xml2array(file_get_contents('feed.xml', 1, 'attribute'));
     */
    public static function xmlToArray($contents, $get_attributes = 0, $priority = 'tag')
    {
        if (empty($contents)) {
            return null;
        }

        if (!function_exists('xml_parser_create')) {
            //print "'xml_parser_create()' function not found!";
            return null;
        }

        //Get the XML parser of PHP - PHP must have this module for the parser to work
        $parser = xml_parser_create('');
        xml_parser_set_option(
            $parser,
            XML_OPTION_TARGET_ENCODING,
            "UTF-8"
        ); # http://minutillo.com/steve/weblog/2004/6/17/php-xml-and-character-encodings-a-tale-of-sadness-rage-and-data-loss
        xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0);
        xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 1);
        xml_parse_into_struct($parser, trim($contents), $xml_values);
        xml_parser_free($parser);

        if (!$xml_values) {
            return null;
        } //Hmm...

        //Initializations
        $xml_array = [];
        $current = &$xml_array; //Reference

        //Go through the tags.
        $repeated_tag_index = []; //Multiple tags with same name will be turned into an array
        foreach ($xml_values as $data) {
            unset($attributes, $value); //Remove existing values, or there will be trouble

            //This command will extract these variables into the foreach scope
            // tag(string) , type(string) , level(int) , attributes(array) .
            extract($data); //We could use the array by itself, but this cooler.

            $result = [];
            $attributes_data = [];

            if (isset($value)) {
                if ($priority == 'tag') {
                    $result = $value;
                } else {
                    $result['value'] = $value;
                } //Put the value in a assoc array if we are in the 'Attribute' mode
            }

            //Set the attributes too.
            if (isset($attributes) and $get_attributes) {
                foreach ($attributes as $attr => $val) {
                    if ($priority == 'tag') {
                        $attributes_data[$attr] = $val;
                    } else {
                        $result['attr'][$attr] = $val;
                    } //Set all the attributes in a array called 'attr'
                }
            }

            //See tag status and do the needed.
            /** @var string $type */
            /** @var string $tag */
            /** @var string $level */
            if ($type == "open") { //The starting of the tag '<tag>'
                $parent[$level - 1] = &$current;
                if (!is_array($current) or (!in_array($tag, array_keys($current)))) { //Insert New tag
                    $current[$tag] = $result;
                    if ($attributes_data) {
                        $current[$tag . '_attr'] = $attributes_data;
                    }
                    $repeated_tag_index[$tag . '_' . $level] = 1;

                    $current = &$current[$tag];
                } else { //There was another element with the same tag name

                    if (isset($current[$tag][0])) { //If there is a 0th element it is already an array
                        $current[$tag][$repeated_tag_index[$tag . '_' . $level]] = $result;
                        $repeated_tag_index[$tag . '_' . $level]++;
                    } else { //This section will make the value an array if multiple tags with the same name appear together
                        $current[$tag] = [
                            $current[$tag],
                            $result
                        ]; //This will combine the existing item and the new item together to make an array
                        $repeated_tag_index[$tag . '_' . $level] = 2;

                        if (isset($current[$tag .
                            '_attr'])) { //The attribute of the last(0th) tag must be moved as well
                            $current[$tag]['0_attr'] = $current[$tag . '_attr'];
                            unset($current[$tag . '_attr']);
                        }
                    }
                    $last_item_index = $repeated_tag_index[$tag . '_' . $level] - 1;
                    $current = &$current[$tag][$last_item_index];
                }
            } elseif ($type == "complete") { //Tags that ends in 1 line '<tag />'
                //See if the key is already taken.
                if (!isset($current[$tag])) { //New Key
                    $current[$tag] = (is_array($result) && empty($result)) ? '' : $result;
                    $repeated_tag_index[$tag . '_' . $level] = 1;
                    if ($priority == 'tag' and $attributes_data) {
                        $current[$tag . '_attr'] = $attributes_data;
                    }
                } else { //If taken, put all things inside a list(array)
                    if (isset($current[$tag][0]) and is_array($current[$tag])) { //If it is already an array...

                        // ...push the new element into that array.
                        $current[$tag][$repeated_tag_index[$tag . '_' . $level]] = $result;

                        if ($priority == 'tag' and $get_attributes and $attributes_data) {
                            $current[$tag][$repeated_tag_index[$tag . '_' . $level] . '_attr'] = $attributes_data;
                        }
                        $repeated_tag_index[$tag . '_' . $level]++;
                    } else { //If it is not an array...
                        $current[$tag] = [
                            $current[$tag],
                            $result
                        ]; //...Make it an array using using the existing value and the new value
                        $repeated_tag_index[$tag . '_' . $level] = 1;
                        if ($priority == 'tag' and $get_attributes) {
                            if (isset($current[$tag .
                                '_attr'])) { //The attribute of the last(0th) tag must be moved as well

                                $current[$tag]['0_attr'] = $current[$tag . '_attr'];
                                unset($current[$tag . '_attr']);
                            }

                            if ($attributes_data) {
                                $current[$tag][$repeated_tag_index[$tag . '_' . $level] . '_attr'] = $attributes_data;
                            }
                        }
                        $repeated_tag_index[$tag . '_' . $level]++; //0 and 1 index is already taken
                    }
                }
            } elseif ($type == 'close') { //End of tag '</tag>'
                $current = &$parent[$level - 1];
            }
        }

        return $xml_array;
    }
}