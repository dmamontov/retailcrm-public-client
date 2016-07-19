<?

/**
The MIT License (MIT)

Copyright (c) 2016 Dmitry Mamontov

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
*/

class RetailcrmPublicClient
{
    const METHOD_GET = 'GET';
    const METHOD_POST = 'POST';

    private $curl;

    private $subDomain = '';
    private $url = 'https://%s.retailcrm.ru/';

    private $login = '';
    private $password = '';

    private $csrfToken = '';

    public function __construct($subDomain, $login, $password)
    {
        if (empty($subDomain) || is_null($subDomain)) {
            throw new \InvalidArgumentException('subDomain can not be empty!');
        }

        if (empty($login) || is_null($login)) {
            throw new \InvalidArgumentException('login can not be empty!');
        }

        if (empty($password) || is_null($password)) {
            throw new \InvalidArgumentException('password can not be empty!');
        }

        $this->login = $login;
        $this->password = $password;
        $this->subDomain = $subDomain;
        $this->url = sprintf($this->url, $subDomain);

        if (!$this->checkUrl()) {
            throw new \InvalidArgumentException('retailCRM Account not found!');
        }

        $this->csrfToken = $this->getCsrfToken();
        if (!$this->csrfToken) {
            throw new \InvalidArgumentException('CsrfToken not found!');
        }

        $this->login();
    }

    public function __destruct()
    {
        if ($this->curl) {
            curl_close($this->curl);
        }

        if (file_exists('/tmp/retailcrm_cookie.txt')) {
            @unlink('/tmp/retailcrm_cookie.txt');
        }
    }

    public function importIcml($siteId)
    {
        if (empty($siteId) || is_null($siteId)) {
            throw new \InvalidArgumentException('siteId can not be empty!');
        }

        $data = $this->getSiteSettings($siteId);
        if (!$data || count($data) < 1) {
            return false;
        }

        $data['addJobForYmlLoading'] = 1;

        try {
            $this->curlRequest(
                sprintf('%sadmin/sites/%d/update', $this->url, $siteId),
                self::METHOD_POST,
                array(
                    'intaro_crmbundle_sitetype' => $data
                ),
                array(
                    'Content-Type: application/x-www-form-urlencoded'
                )
            );
        } catch (Exception $e) {
            throw new \InvalidArgumentException($e->getMessage(), $e->getCode());
        }

        return true;
    }

    public function clearCatalog($siteId)
    {
        if (empty($siteId) || is_null($siteId)) {
            throw new \InvalidArgumentException('siteId can not be empty!');
        }

        $data = $this->getSiteSettings($siteId);
        if (!$data || count($data) < 1) {
            return false;
        }

        $data['addJobForRemoving'] = 1;

        try {
            $this->curlRequest(
                sprintf('%sadmin/sites/%d/update', $this->url, $siteId),
                self::METHOD_POST,
                array(
                    'intaro_crmbundle_sitetype' => $data
                ),
                array(
                    'Content-Type: application/x-www-form-urlencoded'
                )
            );
        } catch (Exception $e) {
            throw new \InvalidArgumentException($e->getMessage(), $e->getCode());
        }

        return true;
    }

    public function getSiteSettings($siteId)
    {
        if (empty($siteId) || is_null($siteId)) {
            throw new \InvalidArgumentException('siteId can not be empty!');
        }

        try {
            $response = $this->curlRequest(
                sprintf('%sadmin/sites/%d/edit', $this->url, $siteId),
                self::METHOD_GET
            );
        } catch (Exception $e) {
            throw new \InvalidArgumentException($e->getMessage(), $e->getCode());
        }

        return $this->getFormData($response, sprintf('//form[@action="/admin/sites/%d/update"]', $siteId));
    }

    private function getFormData(\DomDocument $response, $query)
    {
        $xpath = new \DomXpath($response);
        $form = $xpath->query($query);
        if ($form->length < 1) {
            return false;
        }

        $code = "\$data = array();\n";

        $form = $form->item(0);

        $input = $form->getElementsByTagName('input');

        for ($i = 0; $i < $input->length; $i++) {
            $current = $input->item($i);

            if ($current->getAttribute('type') == 'checkbox' && !$current->hasAttribute('checked')) {
                continue;
            }

            $code .= $this->parseFormName($current->getAttribute('name'), $current->getAttribute('value'));
        }

        $select = $form->getElementsByTagName('select');
        for ($i = 0; $i < $select->length; $i++) {
            $current = $select->item($i);

            $value = '';
            $option = $current->getElementsByTagName('option');
            for ($j = 0; $j < $option->length; $j++) {
                $currentOption = $option->item($j);
                if ($currentOption->hasAttribute('selected')) {
                    $value = $currentOption->getAttribute('value');
                    break;
                }
            }

            $code .= $this->parseFormName($current->getAttribute('name'), $value);
        }

        $textArea = $form->getElementsByTagName('textarea');
        for ($i = 0; $i < $textArea->length; $i++) {
            $current = $textArea->item($i);

            $code .= $this->parseFormName($current->getAttribute('name'), $current->textContent);
        }

        $button = $form->getElementsByTagName('button');
        for ($i = 0; $i < $button->length; $i++) {
            $current = $button->item($i);
 
            $code .= $this->parseFormName($current->getAttribute('name'), $current->getAttribute('value'));
        }

        $code .= 'return $data;';

        return @eval($code);
    }

    private function parseFormName($name, $value)
    {
        $result = '';
        if (preg_match("/(\[.*\])/", $name, $matches)) {
            $result = sprintf(
                "\$data%s = '%s';\n",
                str_replace(
                    "['']",
                    '[]',
                    str_replace(array('[', ']'), array("['", "']"), $matches[1])
                ),
                $value
            );
        }

        return $result;
    }

    private function checkUrl()
    {
        try {
            $response = $this->curlRequest($this->url);
        } catch (Exception $e) {
            return false;
        }

        if (stripos($response->textContent, 'Account not found') !== false) {
            return false;
        }

        return true;
    }

    private function getCsrfToken()
    {
        try {
            $response = $this->curlRequest(sprintf('%slogin', $this->url));
        } catch (Exception $e) {
            return false;
        }

        $xpath = new DomXpath($response);
        $input = $xpath->query('//input[@name="_csrf_token"]');
        if ($input->length < 1) {
            return false;
        }

        $input = $input->item(0);

        return $input->getAttribute('value');
    }

    private function login()
    {
        try {
            $response = $this->curlRequest(
                sprintf('%slogin_check', $this->url),
                self::METHOD_POST,
                array(
                    '_username' => $this->login,
                    '_password' => $this->password,
                    '_csrf_token' => $this->csrfToken,
                    '_remember_me' => 'on'
                ),
                array('Content-Type: application/x-www-form-urlencoded')
            );
        } catch (Exception $e) {
            throw new \InvalidArgumentException($e->getMessage(), $e->getCode());
        }

        if (stripos($response->textContent, 'Invalid CSRF token') !== false) {
            throw new \InvalidArgumentException('Invalid CSRF token!');
        }

        if (stripos($response->textContent, 'Неправильное имя пользователя или пароль') !== false) {
            throw new \InvalidArgumentException('Incorrect username or password!');
        }

        return true;
    }

    private function curlRequest($url, $method = 'GET', $parameters = null, $headers = array(), $cookie = '/tmp/retailcrm_cookie.txt', $timeout = 30)
    {
        if ($method == self::METHOD_GET && is_null($parameters) == false) {
            $url .= '?' . http_build_query($parameters);
        }

        if (!$this->curl) {
            $this->curl = curl_init();
        }

        curl_setopt($this->curl, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2228.0 Safari/537.36');
        curl_setopt($this->curl, CURLOPT_URL, $url);
        curl_setopt($this->curl, CURLOPT_FAILONERROR, false);
        curl_setopt($this->curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($this->curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->curl, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($this->curl, CURLOPT_COOKIEFILE, $cookie);
        curl_setopt($this->curl, CURLOPT_COOKIEJAR, $cookie);
        curl_setopt($this->curl, CURLOPT_HEADER, false);
        curl_setopt($this->curl, CURLOPT_FOLLOWLOCATION, true);

        curl_setopt($this->curl, CURLOPT_POST, false);
        curl_setopt($this->curl, CURLOPT_HTTPHEADER, $headers);
        if ($method == self::METHOD_POST && is_null($parameters) === false) {
            curl_setopt($this->curl, CURLOPT_POST, true);
            curl_setopt($this->curl, CURLOPT_POSTFIELDS, http_build_query($parameters));
        }
        $resp = curl_exec($this->curl);
        $statusCode = curl_getinfo($this->curl, CURLINFO_HTTP_CODE);
        $errno = curl_errno($this->curl);
        $error = curl_error($this->curl);

        if ($errno) {
            throw new Exception($error, $errno);
        }

        if ($this->isJson($resp)) {
            $response = @json_decode($resp, true);
        } else {
            $response = new DOMDocument();
            @$response->loadHTML($resp);
        }

        if ($statusCode >= 400) {
            throw new Exception('Error', $statusCode);
        }

        return empty($response) || $response === false ? true : $response;
    }

    private function isJson($string)
    {
        if (is_string($string) == false) {
            return false;
        }

        json_decode($string);
        return (json_last_error() == JSON_ERROR_NONE);
    }
}
