<?php

namespace Defr;

use Defr\Ares\AresException;
use Defr\Ares\AresRecord;
use Defr\Ares\AresRecords;
use Defr\Ares\TaxRecord;
use InvalidArgumentException;
use PHPUnit\Util\Exception;

/**
 * Class Ares provides a way for retrieving data about business subjects from Czech Business register
 * (aka Obchodní rejstřík).
 *
 * @author Dennis Fridrich <fridrich.dennis@gmail.com>
 */
class Ares
{
    const URL_BAS = 'https://wwwinfo.mfcr.cz/cgi-bin/ares/darv_bas.cgi?ico=%s';
    const URL_RES = 'https://wwwinfo.mfcr.cz/cgi-bin/ares/darv_res.cgi?ICO=%s';
    const URL_TAX = 'https://wwwinfo.mfcr.cz/cgi-bin/ares/ares_es.cgi?ico=%s&filtr=0';
    const URL_FIND = 'https://wwwinfo.mfcr.cz/cgi-bin/ares/ares_es.cgi?obch_jm=%s&obec=%s&filtr=0';
    const URL_MULTIPLE = 'https://wwwinfo.mfcr.cz/cgi-bin/ares/xar.cgi';

    /**
     * @var string
     */
    private $cacheStrategy = 'YW';

    /**
     * Path to directory that serves as an local cache for Ares service responses.
     *
     * @var string
     */
    private $cacheDir = null;

    /**
     * Whether we are running in debug/development environment.
     *
     * @var bool
     */
    private $debug;

    /**
     * Load balancing domain URL.
     *
     * @var string
     */
    private $balancer = null;

    /**
     * Stream context options.
     *
     * @var array
     */
    private $contextOptions = [
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ];

    /**
     * Last called URL.
     *
     * @var string
     */
    private $lastUrl;

    /**
     * @param string|null $cacheDir
     * @param bool $debug
     * @param string|null $balancer
     */
    public function __construct($cacheDir = null, $debug = false, $balancer = null)
    {
        if (null === $cacheDir) {
            $cacheDir = sys_get_temp_dir();
        }

        if (null !== $balancer) {
            $this->balancer = $balancer;
        }

        $this->cacheDir = $cacheDir . '/defr/ares';
        $this->debug = $debug;

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0777, true);
        }
    }

    /**
     * @param $id
     *
     * @return AresRecord
     * @throws Ares\AresException
     *
     * @throws InvalidArgumentException
     */
    public function findByIdentificationNumber($id)
    {
        $this->checkCompanyId((string)$id);

        if (empty($id)) {
            throw new AresException('IČ firmy musí být zadáno.');
        }

        $url = $this->composeUrl(sprintf(self::URL_BAS, $id));

        try {
            $aresRequest = file_get_contents($url, false, stream_context_create($this->contextOptions));
            if ($this->debug) {
                file_put_contents($cachedRawFile, $aresRequest);
            }
            $aresResponse = simplexml_load_string($aresRequest);

            if ($aresResponse) {
                $ns = $aresResponse->getDocNamespaces();
                $data = $aresResponse->children($ns['are']);
                $elements = $data->children($ns['D'])->VBAS;

                $ico = $elements->ICO;
                if ((string)$ico !== (string)$id) {
                    throw new AresException('IČ firmy nebylo nalezeno.');
                }

                $record = new AresRecord();

                $record->setCompanyId(strval($elements->ICO));
                $record->setTaxId(strval($elements->DIC));
                $record->setCompanyName(strval($elements->OF));
                if (strval($elements->AA->NU) !== '') {
                    $record->setStreet(strval($elements->AA->NU));
                } else {
                    if (strval($elements->AA->NCO) !== '') {
                        $record->setStreet(strval($elements->AA->NCO));
                    }
                }

                if (strval($elements->AA->CO)) {
                    $record->setStreetHouseNumber(strval($elements->AA->CD));
                    $record->setStreetOrientationNumber(strval($elements->AA->CO));
                } else {
                    $record->setStreetHouseNumber(strval($elements->AA->CD));
                }

                if (strval($elements->AA->N) === 'Praha') {
                    $record->setTown(strval($elements->AA->NMC));
                    $record->setArea(strval($elements->AA->NCO));
                } elseif (strval($elements->AA->NCO) !== strval($elements->AA->N)) {
                    $record->setTown(strval($elements->AA->N));
                    $record->setArea(strval($elements->AA->NCO));
                } else {
                    $record->setTown(strval($elements->AA->N));
                }

                $record->setZip(strval($elements->AA->PSC));

                $record->setRegisters(strval($elements->PSU));

                $stateInfo = $elements->AA->AU->children($ns['U']);
                $record->setStateCode(strval($stateInfo->KK));

                return $record;
            } else {
                throw new AresException('Databáze ARES není dostupná.');
            }
        } catch (\Exception $e) {
            throw new AresException($e->getMessage());
        }

        file_put_contents($cachedFile, serialize($record));

        return $record;
    }

    /**
     * Find subject in RES (Registr ekonomických subjektů)
     *
     * @param $id
     *
     * @return AresRecord
     * @throws Ares\AresException
     *
     * @throws InvalidArgumentException
     */
    public function findInResById($id)
    {
        $this->checkCompanyId($id);

        $url = $this->composeUrl(sprintf(self::URL_RES, $id));

        $cachedFileName = $id . '_' . date($this->cacheStrategy) . '.php';
        $cachedFile = $this->cacheDir . '/res_' . $cachedFileName;
        $cachedRawFile = $this->cacheDir . '/res_raw_' . $cachedFileName;

        if (is_file($cachedFile)) {
            return unserialize(file_get_contents($cachedFile));
        }

        try {
            $aresRequest = file_get_contents($url, false, stream_context_create($this->contextOptions));
            if ($this->debug) {
                file_put_contents($cachedRawFile, $aresRequest);
            }
            $aresResponse = simplexml_load_string($aresRequest);

            if ($aresResponse) {
                $ns = $aresResponse->getDocNamespaces();
                $data = $aresResponse->children($ns['are']);
                $elements = $data->children($ns['D'])->Vypis_RES;

                if (strval($elements->ZAU->ICO) === (string)$id) {
                    $record = new AresRecord();
                    $record->setCompanyId(strval($id));
                    $record->setTaxId($this->findVatById($id));
                    $record->setCompanyName(strval($elements->ZAU->OF));
                    $record->setStreet(strval($elements->SI->NU));
                    $record->setStreetHouseNumber(strval($elements->SI->CD));
                    $record->setStreetOrientationNumber(strval($elements->SI->CO));
                    $record->setTown(strval($elements->SI->N));
                    $record->setZip(strval($elements->SI->PSC));
                } else {
                    throw new AresException('IČ firmy nebylo nalezeno.');
                }
            } else {
                throw new AresException('Databáze ARES není dostupná.');
            }
        } catch (\Exception $e) {
            throw new AresException($e->getMessage());
        }
        file_put_contents($cachedFile, serialize($record));

        return $record;
    }

    /**
     * Find subject's tax ID in OR (Obchodní rejstřík).
     * @param $id
     *
     * @return string
     * @throws \Exception
     *
     * @throws InvalidArgumentException
     */
    public function findVatById($id)
    {
        $this->checkCompanyId($id);

        $url = $this->composeUrl(sprintf(self::URL_TAX, $id));

        $cachedFileName = $id . '_' . date($this->cacheStrategy) . '.php';
        $cachedFile = $this->cacheDir . '/tax_' . $cachedFileName;
        $cachedRawFile = $this->cacheDir . '/tax_raw_' . $cachedFileName;

        if (is_file($cachedFile)) {
            return unserialize(file_get_contents($cachedFile));
        }

        try {
            $vatRequest = file_get_contents($url, false, stream_context_create($this->contextOptions));
            if ($this->debug) {
                file_put_contents($cachedRawFile, $vatRequest);
            }
            $vatResponse = simplexml_load_string($vatRequest);

            if ($vatResponse) {
                $ns = $vatResponse->getDocNamespaces();
                $data = $vatResponse->children($ns['are']);
                $elements = $data->children($ns['dtt'])->V->S;

                if ($elements && strval($elements->ico) === (string)$id) {
                    $record = new TaxRecord(str_replace('dic=', 'CZ', strval($elements->p_dph)));
                } else {
                    $record = null;
                }
            } else {
                throw new AresException('Databáze MFČR není dostupná.');
            }
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
        file_put_contents($cachedFile, serialize($record));

        return $record;
    }

    /**
     * Find subject by its name in OR (Obchodní rejstřík).
     *
     * @param string $name
     * @param string|null $city
     *
     * @return array|AresRecord[]|AresRecords
     * @throws AresException
     *
     * @throws InvalidArgumentException
     */
    public function findByName($name, $city = null)
    {
        if (strlen($name) < 3) {
            throw new InvalidArgumentException('Zadejte minimálně 3 znaky pro hledání.');
        }

        $url = $this->composeUrl(sprintf(
            self::URL_FIND,
            urlencode(Lib::stripDiacritics($name)),
            urlencode(Lib::stripDiacritics($city))
        ));

        $cachedFileName = date($this->cacheStrategy) . '_' . md5($name . $city) . '.php';
        $cachedFile = $this->cacheDir . '/find_' . $cachedFileName;
        $cachedRawFile = $this->cacheDir . '/find_raw_' . $cachedFileName;

        if (is_file($cachedFile)) {
            return unserialize(file_get_contents($cachedFile));
        }

        $aresRequest = file_get_contents($url, false, stream_context_create($this->contextOptions));
        if ($this->debug) {
            file_put_contents($cachedRawFile, $aresRequest);
        }
        $aresResponse = simplexml_load_string($aresRequest);
        if (!$aresResponse) {
            throw new AresException('Databáze ARES není dostupná.');
        }

        $ns = $aresResponse->getDocNamespaces();
        $data = $aresResponse->children($ns['are']);
        $elements = $data->children($ns['dtt'])->V->S;

        if (empty($elements)) {
            throw new AresException('Nic nebylo nalezeno.');
        }

        $records = new AresRecords();
        foreach ($elements as $element) {
            $record = new AresRecord();
            $record->setCompanyId(strval($element->ico));
            $record->setTaxId(
                ($element->dph ? str_replace('dic=', 'CZ', strval($element->p_dph)) : '')
            );
            $record->setCompanyName(strval($element->ojm));
            $records[] = $record;
        }
        file_put_contents($cachedFile, serialize($records));

        return $records;
    }

    /**
     * @param string $cacheStrategy
     */
    public function setCacheStrategy($cacheStrategy)
    {
        $this->cacheStrategy = $cacheStrategy;
    }

    /**
     * @param bool $debug
     */
    public function setDebug($debug)
    {
        $this->debug = $debug;
    }

    /**
     * @param string $balancer
     *
     * @return $this
     */
    public function setBalancer($balancer)
    {
        $this->balancer = $balancer;

        return $this;
    }

    /**
     * @return string
     */
    public function getLastUrl()
    {
        return $this->lastUrl;
    }

    /**
     * @param int $id
     */
    private function checkCompanyId($id)
    {
        if (!is_numeric($id) || !preg_match('/^\d+$/', $id)) {
            throw new InvalidArgumentException('IČ firmy musí být číslo.');
        }
    }

    /**
     * @param string $url
     *
     * @return string
     */
    private function composeUrl($url)
    {
        if ($this->balancer) {
            $url = sprintf('%s?url=%s', $this->balancer, urlencode($url));
        }

        $this->lastUrl = $url;

        return $url;
    }

    public function findByMultipleIds(array $ids): array
    {
        $xml = $this->prepareXMLForMultipleRequest($ids);

        $send_context = stream_context_create(array(
            'http' => array(
                'method' => 'POST',
                'header' => 'Content-Type: application/xml',
                'content' => $xml
            )
        ));

        $aresRecords = [];
        try {
            $aresRequest = file_get_contents(self::URL_MULTIPLE, false, $send_context);

            $aresResponse = simplexml_load_string($aresRequest);
            $data = $aresResponse->xpath("//SOAP-ENV:Body/*")[0];
            $odpovedi = $data->children("http://wwwinfo.mfcr.cz/ares/xml_doc/schemas/ares/ares_answer_res/v_1.0.3");

            if ($odpovedi instanceof \SimpleXMLElement) {
                foreach ($odpovedi->Odpoved as $item) {
                    $resolvedItem = $item->children('D', true)->Vypis_RES;

                    if (isSet($resolvedItem->ZAU)) {
                        $record = new AresRecord();
                        $ico = strval($resolvedItem->ZAU->ICO);
                        $record->setCompanyId($ico);

                        try {
                            $taxId = $this->findVatById($ico);
                            if ($taxId){
                                $record->setTaxId($taxId->getTaxId());
                            }
                        } catch (Exception $exception){
                            $record->setTaxId(null);
                        }
                        $record->setCompanyName(strval($resolvedItem->ZAU->OF));
                        if (strval($resolvedItem->SI->NU) !== '') {
                            $record->setStreet(strval($resolvedItem->SI->NU));
                        } else {
                            if (strval($resolvedItem->SI->NCO) !== '') {
                                $record->setStreet(strval($resolvedItem->SI->NCO));
                            }
                        }

                        if (strval($resolvedItem->SI->CO)) {
                            $record->setStreetHouseNumber(strval($resolvedItem->SI->CD));
                            $record->setStreetOrientationNumber(strval($resolvedItem->SI->CO));
                        } else {
                            $record->setStreetHouseNumber(strval($resolvedItem->SI->CD));
                        }

                        if (strval($resolvedItem->SI->N) === 'Praha') {
                            $record->setTown(strval($resolvedItem->SI->N));
                            $record->setArea(strval($resolvedItem->SI->NCO));
                        } elseif (strval($resolvedItem->SI->NCO) !== strval($resolvedItem->SI->N)) {
                            $record->setTown(strval($resolvedItem->SI->N));
                            $record->setArea(strval($resolvedItem->SI->NCO));
                        } else {
                            $record->setTown(strval($resolvedItem->SI->N));
                        }

                        $record->setZip(strval($resolvedItem->SI->PSC));

                        $aresRecords[] = $record;
                    }
                }
            }

        } catch (\Exception $e) {
            throw new AresException($e->getMessage());
        }

        return $aresRecords;
    }

    private function prepareXMLForMultipleRequest(array $ids): string
    {
        $xml = '<are:Ares_dotazy xmlns:are="http://wwwinfo.mfcr.cz/ares/xml_doc/schemas/ares/ares_request_orrg/v_1.0.0" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://wwwinfo.mfcr.cz/ares/xml_doc/schemas/ares/ares_request_orrg/v_1.0.0 http://wwwinfo.mfcr.cz/ares/xml_doc/schemas/ares/ares_request_orrg/v_1.0.0/ares_request_orrg.xsd" dotaz_datum_cas="2011-06-16T10:01:00" dotaz_pocet="' . count($ids) . '" dotaz_typ="Vypis_RES" vystup_format="XML" validation_XSLT="http://wwwinfo.mfcr.cz/ares/xml_doc/schemas/ares/ares_answer/v_1.0.0/ares_answer.xsl" user_mail="%s" answerNamespaceRequired="http://wwwinfo.mfcr.cz/ares/xml_doc/schemas/ares/ares_answer_res/v_1.0.3" Id="Ares_dotaz">';
        foreach ($ids as $key => $id) {
            $bodyXml = '<Dotaz><Pomocne_ID>' . ($key + 1) . '</Pomocne_ID><ICO>' . $id . '</ICO></Dotaz>';
            $xml .= $bodyXml;
        }
        $xml .= '</are:Ares_dotazy>';

        return $xml;
    }
}
