<?php
/**
 * Class LinkParser
 *
 * ```php
 *  $videoData = new LinkParser([
 *      'uploadPath' => __DIR__.'/../../../uploads/links_parser',
 *      'uploadUrl' => '/uploads/links_parser',
 *      'url' => '{video_url}'
 *  ]);
 *  $videoData->send();
 * ```
 */
class LinkParser
{
    /**
     * Max number of parsing images
     */
    CONST NUMBER_OF_IMAGES = 1;

    /**
     * Min width of parsing images
     */
    CONST MIN_WIDTH_OF_IMG = 50;

    /**
     * Min height of parsing images
     */
    CONST MIN_HEIGHT_OF_IMG = 50;

    /**
     * @var string Needle url
     */
    public $url;

    /**
     * example: /var/www/path/../project/{upload_directory}
     * @var string Full path to web open directory
     */
    public $uploadPath = '';

    /** example: http://{this_host}/{upload_directory} or /{upload_directory}
     * @var string Full or relative url to uploads directory
     */
    public $uploadUrl;

    /**
     * @var array Errors will be returned if something goes wrong
     */
    public $errors = [];

    /**
     * @var array Result will be return if errors is empty
     */
    public $result;

    /**
     * @var array Key-hosting name, value-regexp for find needle hosting by regexp
     */
    public $hostingsRegExp = [
        'youtube' => 'youtu',
        'vimeo' => 'vimeo',
        'rutube' => 'rutube',
        'coub' => 'coub'
    ];

    public $debug = false;
    /**
     * LinkParser constructor.
     * Set correct header and fill class properties
     * @param array $properties
     */
    public function __construct($debug = false){
        $this->debug = $debug;
    }

    /**
     * Find hosting by regexp
     * @return string|boolean
     */
    public function findHosting()
    {
        foreach($this->hostingsRegExp as $hosting => $regExp) {
            if(preg_match('~'.$regExp.'~i', $this->url, $matches)) {
                $hosting = 'get'.ucfirst($hosting);
                return $hosting;
            }
        }

        return false;
    }

    /**
     * @param string $url
     * @param bool $onlyHeaders
     * @return array|bool|mixed
     */
    public function doRequest($url, $onlyHeaders = false, &$res = array())
    {

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, "inparadise.info link parser");
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Accept: text/html, */*;charset=UTF-8',
            'Accept-Charset: UTF-8',
            'accept-language:en-EN',)
        );

        $response = curl_exec($ch);
        $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if($status_code >= 300 && $status_code <= 399)
        {
            preg_match("#Location: ([^\n\r]*?)[\r\n]#si", $response, $match);
            if(isset($match[1]))
            {
                return $this->doRequest($match[1], $onlyHeaders);
            }
        }

        if(empty($response)) {
            return false;
        }

        list($headers, $response) = explode("\r\n\r\n", $response, 2);

        $res['headers'] = $headers;
        $res['response'] = $response;
        $headers = explode("\n", $headers);
        if($onlyHeaders == true) {
            return $headers;
        } else {
            return $response;
        }
    }

    /**
     * Do request by url
     * @return string
     */
    protected function getContent()
    {
        $result = $this->doRequest($this->url, false, $res);

        if($result == false)
        {
            $this->errors['url'] = 'Invalid url';
        }

        if(preg_match("#charset=([A-z0-9\-]+)#u", $res['headers'], $charset))
        {
            $result = iconv($charset[1], "UTF-8//IGNORE", $result);
        }
        if($this->debug) var_dump($result);

        $this->checkErrors();
        $this->result['title'] = $this->getTitle($result);
        $this->result['description'] = $this->getDescription($result);
        return $result;
    }

    /**
     * Parse title from page
     * @param string $content
     * @param string $pattern
     * @return null|string
     */
    protected function getTitle($content, $pattern = '~<title[^>]*>(.*?)</title>~umi')
    {
        if(preg_match($pattern, $content, $matches)) {
            return $matches[1];
        }

        return "";
    }

    /**
     * If have errors send json with errors and exit
     */
    public function checkErrors()
    {
        if(!empty($this->errors)) {
            $response['status'] = 'error';
            $response['errors'] = $this->errors;

            return $response;
        }
    }

    /**
     * Uploads thumb by url to current project
     */
    protected function uploadThumb()
    {
        if(!empty($this->result['image_src'])) {
            $thumbUrl = $this->result['image_src'];
            $image = $this->doRequest($thumbUrl);

            if($image != false) {
                $uniqueName = uniqid();
                $path = $this->uploadPath.'/'.$uniqueName;

                file_put_contents($path, $image);
                $this->result['image_src'] = empty($this->uploadUrl) ? $path : $this->uploadUrl.'/'.$uniqueName;
            }
        }
    }

    function parseDescription($html) {
            // Get the 'content' attribute value in a <meta name="description" ... />
            $matches = array();
            // Search for <meta name="description" content="Buy my stuff" />
            preg_match('/<meta.*?name=("|\')description("|\').*?content=("|\')(.*?)("|\')/i', $html, $matches);
            if (count($matches) > 4) {
                    return trim($matches[4]);
            }
            // Order of attributes could be swapped around: <meta content="Buy my stuff" name="description" />
            preg_match('/<meta.*?content=("|\')(.*?)("|\').*?name=("|\')description("|\')/i', $html, $matches);
            if (count($matches) > 2) {
                    return trim($matches[2]);
            }
            // No match
            return null;
    }

    /**
     * Description from meta tag
     * @return null|string
     */
    protected function getDescription($htmlText)
    {
        $description = "";
        if(preg_match('#<meta +content ?= ?[\'"](.*?)[\'"] +name ?= ?[\'"]description[\'"]#iu', $htmlText, $r))
        {
            $description = $r[1];
        }
        else if(preg_match('#<meta +name ?= ?[\'"]description[\'"] +content=[\'"](.*?)[\'"]#iu', $htmlText, $r))
        {
            $description = $r[1];
        }
        else
        {
            $doc = new DOMDocument();
            @$doc->loadHTML($htmlText);
            $metas  = $doc->getElementsByTagName('meta');
            for ($i = 0; $i < $metas->length; $i++)
            {
                $meta = $metas->item($i);
                if($meta->getAttribute('name') == 'description')
                {
                    $description = $meta->getAttribute('content');
                    break;
                }
            }
        }
        return $description;
    }

    /**
     * Special tag for getting logo
     * @param $content
     * @return bool|string
     */
    protected function getImageSrc($content)
    {
        if(preg_match('~<link[^>]*(rel="image_src")[^>]*href="([^"]*)"[^>]*>~i', $content, $match)) {
            return $this->getLink($match[2]);
        } elseif(preg_match('~<link[^>]*href="([^"]*)"[^>]*(rel="image_src")[^>]*>~i', $content, $match)) {
            return $this->getLink($match[1]);
        } else {
            return false;
        }
    }

    /**
     * Find og:image tag
     * @param $content
     * @return bool|string
     */
    protected function getOpenGraphImage($content)
    {
        $dom = new DOMDocument();
        @$dom->loadHTML($content);
        foreach($dom->getElementsByTagName('meta') as $meta) {
            if($meta->getAttribute('property') == 'og:image') {
                $meta = $meta->getAttribute('content');
                return $this->getLink($meta);
            }
        }
    }

    /**
     * If link is not video parse images
     */
    protected function parseSimpleLink()
    {
        $content = $this->getContent();
        if($imageSrc = $this->getImageSrc($content)) {
            $this->result['image_src'] = $imageSrc;
        } elseif($ogImage = $this->getOpenGraphImage($content)) {
            $this->result['image_src'] = $ogImage;
        } else {
            $this->result['image_src'] = @$this->parseImages($content)[0];
        }
    }

    /**
     * Return array sizes of image and if image successfully uploaded, links to images in current server
     * @param string $url
     * @return array|bool
     */
    protected function getImageSizes($url)
    {
        $image = $this->doRequest($url);
        if(empty($image)) {
            return false;
        } else {
            //$uniqueName = uniqid().'.'.substr(array_pop(explode('.', $url)), 0, 3);
            $name = uniqid();
            $path = $this->uploadPath.'/'.$name;
            file_put_contents($path, $image);

            return array_merge(getimagesize($path), ['image_path' => $path, 'image_url' => $this->uploadUrl.'/'.$name]);
        }
    }

    /**
     * Parse first self::NUMBER_OF_IMAGES, that suitable in min width and min height
     * @param string $page
     * @return array|bool
     */
    protected function parseImages($page)
    {
        $regex = '~<img[^>]*src=[\'|"]([^"]*)[\'|"]~i';
        if(preg_match_all($regex, $page, $images) !== false)
        {
            $images = $images[1];
            $urlHost = $this->getFullHost($this->url);

            $result = [];
            foreach($images as $imageUrl) {
                $path = parse_url($imageUrl, PHP_URL_PATH);
                $queryParams = parse_url($imageUrl, PHP_URL_QUERY) === false ? NULL : parse_url($imageUrl, PHP_URL_QUERY);
                $imageUri = $path.'?'.$queryParams;
                $fullUrl = trim($urlHost.$imageUri);

                if(preg_match('~^//~i', $imageUrl)) {
                    $url = trim($this->getScheme($this->url).':'.$imageUrl);
                    $link = $this->getLink($url);
                    if($link !== false) {
                        $result[] = $link;
                    }
                } elseif(preg_match('~^http~i', $imageUrl)) {
                    $url = trim($imageUrl);
                    $link = $this->getLink($url);
                    if($link !== false) {
                        $result[] = $link;
                    }
                } else {
                    $url = $fullUrl;
                    $link = $this->getLink($url);
                    if($link !== false) {
                        $result[] = $link;
                    }
                }

                if(count($result) >= self::NUMBER_OF_IMAGES) {
                    break;
                }
            }
            return $result;
        } else {
            return false;
        }
    }

    /**
     * Link to image on current server
     * @param string $url
     * @return bool
     */
    protected function getLink($url)
    {
        $imageData = $this->getImageSizes($url);
        if(empty($imageData)) {
            return false;
        } else {
            if((intval($imageData[0]) >= self::MIN_WIDTH_OF_IMG || intval($imageData[1]) >= self::MIN_HEIGHT_OF_IMG)) {
                return $imageData['image_url'];
            } else {
                unlink($imageData['image_path']);
                return false;
            }
        }
    }

    /**
     * Get full host with scheme
     * @param $url
     * @return null|string
     */
    protected function getFullHost($url)
    {
        $scheme = empty($this->getScheme($url)) ? NULL : ($this->getScheme($url).':');
        $host = $this->getHost($url);

        return (!empty($host)) ? $scheme.'//'.$host : NULL;
    }

    /**
     * Get host without scheme
     * @param $url
     * @return mixed|null
     */
    protected function getHost($url)
    {
        $host = parse_url($url, PHP_URL_HOST);
        return $host === false ? NULL : $host;
    }

    /**
     * Return scheme or null
     * @param $url
     * @return mixed|null
     */
    protected function getScheme($url)
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        return $scheme === false ? NULL : $scheme;
    }

    /**
     * Send result json
     */
    public function send(array $properties)
    {
        foreach($properties as $property => $value) {
            $this->$property = $value;
        }

        $this->result['description'] = '';
        $this->result['title'] = '';


        $getHosting = $this->findHosting();
        if($getHosting !== false)
        {
            $this->$getHosting();
            $this->uploadThumb();
            $this->result['type'] = 'video';
            $this->result['hosting'] = $getHosting;
            return $this->result;
        }


        // Если картинка определим это по заголовкам
        $headers = $this->doRequest($properties['url'], true, $res);
        if($headers)
        {
            foreach($headers as $header)
            {
                if(preg_match("~image\/~i", $header))
                {
                    $this->result['image_src'] = $this->getLink($properties['url']);
                    $this->result['status'] = 'OK';
                    $this->result['type'] = 'link';
                    return $this->result;
                }
            }
        }

        $this->parseSimpleLink();
        $this->result['type'] = 'link';
        return $this->result;
    }

    /**
     * Parse youtube page
     */
    public function getYoutube()
    {
        $content = $this->getContent();
        $thumbnailPattern = '~<link itemprop="thumbnailUrl" href="(.*)">~i';
        if(preg_match($thumbnailPattern, $content, $matches)) {
            $this->result['image_src'] = $matches[1];
        }
    }

    /**
     * Parse vimeo page
     */
    public function getVimeo()
    {
        $content = $this->getContent();
        $thumbnailPattern = '~"thumbnailUrl":"([^"]*)"~i';
        //$thumbnailPattern = '~thumbnailUrl" content="(.*)"~i';
        if(preg_match($thumbnailPattern, $content, $matches)) {
            $this->result['image_src'] = $matches[1];
        }
    }

    /**
     * Parse rutube page
     */
    public function getRutube()
    {
        $content = $this->getContent();
        $thumbnailPattern = '~thumbnailUrl" content="(.*)"~i';
        //$thumbnailPattern = '~"thumbnailUrl": "([^"]*)"~i';
        if(preg_match($thumbnailPattern, $content, $matches)) {
            $this->result['image_src'] = $matches[1];
        }
    }

    /**
     * Parse coub page
     */
    public function getCoub()
    {
        $content = $this->getContent();
        $thumbnailPattern = "~<link href=('|\")(.*)('|\") rel=('|\")thumbnail('|\")~i";
        if(preg_match($thumbnailPattern, $content, $matches)) {
            $this->result['image_src'] = $matches[2];
        }
    }
}
