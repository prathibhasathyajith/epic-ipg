<?php
/**
 * Created by PhpStorm.
 * User: prathibha_w
 * Date: 8/06/2018
 * Time: 11:59 AM
 */

function PemToDer($Pem)
{
    //Split lines:
    $lines = explode("\n", trim($Pem));
    //Remove last and first line:
    unset($lines[count($lines) - 1]);
    unset($lines[0]);
    //unset($lines[1]);
    //Join remaining lines:
    $result = implode('', $lines);
    //Decode:
    $result = base64_decode($result);
    return $result;
}


class ASNValue
{
    const TAG_INTEGER = 0x02;
    const TAG_BITSTRING = 0x03;
    const TAG_SEQUENCE = 0x30;

    public $Tag;
    public $Value;

    function __construct($Tag = 0x00, $Value = '')
    {
        $this->Tag = $Tag;
        $this->Value = $Value;
    }


    function Decode(&$Buffer)
    {
        //Read type
        $this->Tag = self::ReadByte($Buffer);

        //Read first byte
        $firstByte = self::ReadByte($Buffer);

        if ($firstByte < 127) {
            $size = $firstByte;
        } else if ($firstByte > 127) {
            $sizeLen = $firstByte - 0x80;
            //Read length sequence
            $size = self::BinToInt(self::ReadBytes($Buffer, $sizeLen));
        } else {
            throw new Exception("Invalid ASN length value");
        }

        $this->Value = self::ReadBytes($Buffer, $size);
    }

    protected static function ReadBytes(&$Buffer, $Length)
    {
        $result = substr($Buffer, 0, $Length);
        $Buffer = substr($Buffer, $Length);

        return $result;
    }

    protected static function ReadByte(&$Buffer)
    {
        return ord(self::ReadBytes($Buffer, 1));
    }

    protected static function BinToInt($Bin)
    {
        $len = strlen($Bin);
        $result = 0;
        for ($i = 0; $i < $len; $i++) {
            $curByte = self::ReadByte($Bin);
            $result += $curByte << (($len - $i - 1) * 8);
        }

        return $result;
    }


    function GetIntBuffer()
    {
        $result = $this->Value;
        if (ord($result{0}) == 0x00) {
            $result = substr($result, 1);
        }

        return $result;
    }


    function GetInt()
    {
        $result = $this->GetIntBuffer();
        $result = self::BinToInt($result);

        return $result;
    }


    function GetSequence()
    {
        $result = array();
        $seq = $this->Value;
        while (strlen($seq)) {
            $val = new ASNValue();
            $val->Decode($seq);
            $result[] = $val;
        }

        return $result;
    }
}

/**
 * @param $url
 * @return bool|string
 * get private key from pem file
 */
function getPVTKey($url)
{
    $fp = fopen($url, "r");
    $priv_key = fread($fp, 8192);
    fclose($fp);
    return $priv_key;

}

/**
 * @param $pvt_key
 * @return bool|string
 * @throws Exception
 * generate key for SymmetricKey Encryption (Shared Key)
 */
function generateKey($pvt_key)
{
    try {
        $PrivateDER = PemToDer($pvt_key);
        $body = new ASNValue;
        $body->Decode($PrivateDER);
        $bodyItems = $body->GetSequence();
        $Modulus = $bodyItems[1]->GetIntBuffer();
        $bin2hex = bin2hex($Modulus);
        $hexdec = (String)hexdec($bin2hex);

        $new = str_replace(".", "", $hexdec);
        $key = substr($new, 0, 8);
    } catch (Exception $s) {
        throw $s;
    }
    return $key;
}

/**
 * @param $input
 * @param $key
 * @return string
 * encrypted mid
 * @throws Exception
 */
function encrypt($input, $key)
{
    try {
        $size = mcrypt_get_block_size(MCRYPT_DES, MCRYPT_MODE_ECB);
        $input = pkcs5_pad($input, $size);
        $td = mcrypt_module_open(MCRYPT_DES, '', MCRYPT_MODE_ECB, '');
        $iv = mcrypt_create_iv(mcrypt_enc_get_iv_size($td), MCRYPT_RAND);
        mcrypt_generic_init($td, $key, $iv);
        $data = mcrypt_generic($td, $input);
        mcrypt_generic_deinit($td);
        mcrypt_module_close($td);
        //  $data = base64_encode($data);
    }catch (Exception $e){
        throw $e;
    }
    return strtoupper (bin2hex($data));
}

function pkcs5_pad($text, $blocksize)
{
    $pad = $blocksize - (strlen($text) % $blocksize);
    return $text . str_repeat(chr($pad), $pad);
}

/**
 * @param $myData
 * @param $pvt_key
 * @return string
 * digitally sign
 * @throws Exception
 */
function digitalsign($dsdata, $pvt_key,$mid,$url)
{
    try {
        $data = $dsdata;
        $private_key_res = openssl_get_privatekey(getPVTKey(getUrlFromContext($url,$mid)), "password");

        $SignedData = openssl_sign($data, $signature, $private_key_res, "md5WithRSAEncryption");

    }catch (Exception $e){
        throw $e;
    }
    return strtoupper (bin2hex($signature));
}

/**
 * @param $MID
 * @return string
 * get pem file URL
 */
function getURL($MID)
{
    $target_dir = "../wp-content/plugins/" . basename(__DIR__) . "/keystore/" . $MID . ".pem";
    return $target_dir;
}

/**
 * @param $MID
 * @return string
 * get pem url method 2
 */
function getUrlFromContext($URL,$MID)
{
    return $URL.$MID.".pem";
}


