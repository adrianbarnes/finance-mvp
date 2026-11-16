<?php

namespace App\Services\Telephony\Twilio;

use DOMDocument;
use DOMElement;

/**
 * Builds a TwiML document: a <Response> holding verbs such as <Play>,
 * <Say>, <Gather>, <Record>, <Redirect> and <Hangup>.
 */
final class Twiml
{
    private DOMDocument $document;

    private DOMElement $response;

    public function __construct()
    {
        $this->document = new DOMDocument('1.0', 'UTF-8');
        $this->response = $this->document->createElement('Response');
        $this->document->appendChild($this->response);
    }

    /**
     * Add a verb to the response, or nest it in another verb.
     *
     * @param  array<string, string|int|bool|null>  $attributes  Null attributes are left out
     */
    public function add(string $verb, array $attributes = [], ?string $text = null, ?DOMElement $parent = null): DOMElement
    {
        $element = $this->document->createElement($verb);

        foreach ($attributes as $name => $value) {
            if ($value !== null) {
                $element->setAttribute($name, is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
            }
        }

        if ($text !== null) {
            $element->appendChild($this->document->createTextNode($text));
        }

        ($parent ?? $this->response)->appendChild($element);

        return $element;
    }

    public function toXml(): string
    {
        return (string) $this->document->saveXML();
    }
}
