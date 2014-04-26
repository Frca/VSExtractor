<?php

function main() {
    header('Content-Type: text/html; charset=utf-8');

    $filename = $_SERVER['QUERY_STRING'];
    $data = isset($_GET["refresh"]) && $_GET["refresh"] ? NULL : loadFromCache($filename);
    if (!$data) {
        $data = loadData();
        saveToCache($filename, $data);
    }

    return $data;
}

function loadData() {
    $json = loadFaculties();
    if (isset($_GET["format"]) && $_GET["format"] == "html") {
        return prettify(preg_replace_callback('/\\\\u([0-9a-f]{4})/i', 'replace_unicode_escape_sequence', json_encode($json)));
    }
    else
        return json_encode($json);
}

function loadFaculties() {
    $json = array();
    for ($facultyId = 1; $facultyId < 7; ++$facultyId) {
        $data = array("fakulta" => $facultyId * 10);
        $res = loadPageByData($data);
        $json[] = handleBaseFacultyData($facultyId, $res);
    }

    return $json;
}

function handleBaseFacultyData($id, $result) {
    $json = new stdClass();
    $json->id = $id;
    $json->name = $result->query("//table[1]/tbody/tr/td[2]")->item(0)->nodeValue;
    $json->code = array();

    $plans = array(
        5 => array(1, 2, 4),
        23 => array(3)
    );

    $rows = $result->query("//table[2]/tbody/tr");
    $data = array();
    foreach ($rows as $row) {
        $cells = $result->query("td", $row);
        $periodNameCell = $cells->item(1);
        $linkCell = $cells->item(2);

        $period = $periodNameCell->nodeValue;
        $link = $result->query("small/a", $linkCell)->item(0)->attributes->getNamedItem("href")->nodeValue;

        $args = getArgsFromLink($link);

        if (!in_array($args["typ_ss"], array_keys($plans)))
            continue;

        $winter = array("ZS", "WS");
        list($semester, $years) = explode(" ", $period);
        if (!in_array($semester, $winter))
            continue;

        list($fy, $sy) = explode("/", $years);
        $currentYear = date("Y");
        if ($currentYear == (date("n") < 9 ? $sy : $fy)) {
            $data[] = $args;
        }
    }

    $json->types = array();
    foreach ($data as $args) {
        foreach ($plans[$args["typ_ss"]] as $type) {
            $args["typ_studia"] = $type;
            $res = loadPageByData($args);
            $output = handleProgramData($res);
            $json->code[] = $output->code;
            $json->types[] = $output->types;
        }
    }

    $json->code = array_unique($json->code);

    return $json;
}

function handleProgramData($result) {
    $json = new stdClass();
    $json->code = array();

    $facultyCodeVal = $result->query("//table[1]/tbody/tr[3]/td[2]")->item(0)->nodeValue;
    $facultyCode = substr($facultyCodeVal, strrpos($facultyCodeVal, " ")+1);

    $json->type = $result->query("//table[1]/tbody/tr[4]/td[2]")->item(0)->nodeValue;
    $programmeRows = $result->query("//table[2]/tbody/tr");

    $json->programmes = array();
    foreach($programmeRows as $row) {
        $cells = $result->query("td", $row);
        $firstCell = $cells->item(0);
        $nameCell = $cells->item(1);

        if ($firstCell->hasAttribute("width"))
            break;

        $linkCell = $cells->item(5);

        $name = $nameCell->nodeValue;
        $link = $result->query("small/a", $linkCell)->item(0)->attributes->getNamedItem("href")->nodeValue;
        $args = getArgsFromLink($link);

        list($code, $programmeName) = explode(" ", $name, 2);

        $res = loadPageByData($args);

        $json->code[] = getFirstCodePart($code);

        $programme = new stdClass();
        $programme->code = getLastCodePart($code);
        $programme->name = $programmeName;
        $programme->fields = handleFieldsData($res);

        $json->programmes[] = $programme;
    }

    $json->code = array_unique($json->code);

    $specRows = $result->query("//table[3]/tbody/tr");
    $json->specializations = array();
    foreach($specRows as $row) {
        $cells = $result->query("td", $row);
        $firstCell = $cells->item(0);
        $nameCell = $cells->item(1);

        if ($firstCell->hasAttribute("width"))
            break;

        $specName = $nameCell->nodeValue;
        list($code, $name) = explode(" ", $specName, 2);

        $specialization = new stdClass();
        $specialization->code = $code;
        $specialization->name = $name;

        $json->specializations[] = $specialization;
    }

    $object = new stdClass();
    $object->types = $json;
    $object->code = $facultyCode;
    return $object;
}

function handleFieldsData($result) {
    $programmeRows = $result->query("//table[2]/tbody/tr");
    $fields = array();
    foreach($programmeRows as $row) {
        $cells = $result->query("td", $row);
        $codeCell = $cells->item(0);
        $nameCell = $cells->item(1);

        $field = new stdClass();
        $field->code = getLastCodePart($codeCell->nodeValue);
        $field->name = $nameCell->nodeValue;

        $fields[] = $field;
    }

    return $fields;

}

function loadPageByData(array $data) {
    return loadPage("http://isis.vse.cz/katalog/plany.pl?" . http_build_query($data, null, ';'));
}

function loadPage($url) {
    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_HEADER, 0);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    curl_setopt($ch, CURLOPT_TIMEOUT, 20);

    $lang = "en";
    $availableLangs = array("cs", "sk", "en");
    if (isset($_GET["lang"]) && in_array($_GET["lang"], $availableLangs))
        $lang = $_GET["lang"];

    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        "Accept-Language: $lang,en;q=0.8"
    ));

    $dom = new DOMDocument();

    $result = curl_exec($ch);

    @$dom->loadHTML($result);

    curl_close($ch);

    return new DOMXPath($dom);
}

function getArgsFromLink($args) {
    $singleArg = substr($args, strpos($args, "?")+1);
    $args = explode(";", $singleArg);
    $result = array();
    foreach ($args as $arg) {
        list($key, $value) = explode("=", $arg, 2);
        $result[$key] = $value;
    }

    return $result;
}

function getLastCodePart($code) {
    $pos = strrpos($code, "-");
    if ($pos === FALSE)
        return $code;
    else
        return substr($code, $pos+1);
}

function getFirstCodePart($code) {
    $pos = strpos($code, "-");
    if ($pos === FALSE)
        return $code;
    else
        return substr($code, 0, $pos);
}

function prettify($json) {
    $result      = '';
    $pos         = 0;
    $strLen      = strlen($json);
    $indentStr   = '&nbsp;&nbsp;&nbsp;&nbsp;';
    $newLine     = "<br/>\n";
    //$indentStr   = '    ';
    //$newLine     = "\n";
    $prevChar    = '';
    $outOfQuotes = true;

    for ($i=0; $i<=$strLen; $i++) {
        $char = substr($json, $i, 1);

        if ($char == '"' && $prevChar != '\\') {
            $outOfQuotes = !$outOfQuotes;
        } else if(($char == '}' || $char == ']') && $outOfQuotes) {
            $result .= $newLine;
            $pos --;
            for ($j=0; $j<$pos; $j++) {
                $result .= $indentStr;
            }
        }

        $result .= $char;

        if (($char == ',' || $char == '{' || $char == '[') && $outOfQuotes) {
            $result .= $newLine;
            if ($char == '{' || $char == '[') {
                $pos ++;
            }

            for ($j = 0; $j < $pos; $j++) {
                $result .= $indentStr;
            }
        }

        $prevChar = $char;
    }

    return $result;
}

function replace_unicode_escape_sequence($match) {
    return mb_convert_encoding(pack('H*', $match[1]), 'UTF-8', 'UCS-2BE');
}

function loadFromCache($name) {
    $key = generateKey($name);
    if (file_exists($key)) {
        $json = json_decode(file_get_contents($key));
        if ($json->expires > time())
            return $json->data;
    }

    return NULL;
}

function saveToCache($name, $data) {
    $key = generateKey($name);

    $json = new stdClass();
    $json->expires = time()  + 24 * 60 * 60 ;
    $json->data = $data;

    file_put_contents($key, json_encode($json));
}

function generateKey($name) {
    if (!file_exists("cache"))
        mkdir("cache");

    return "cache/" . md5(is_scalar($name) ? $name : serialize($name));
}

echo main();
