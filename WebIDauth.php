<?
//-----------------------------------------------------------------------------------------------------------------------------------
//
// Filename   : WebIDauth.php
// Date       : 5th Apr 2011
//
// Version 0.2
// 
// Copyright 2011 fcns.eu
// Author: Andrei Sambra - andrei@fcns.eu
//
// This program is free software: you can redistribute it and/or modify
// it under the terms of the GNU Affero General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU Affero General Public License for more details.
//
// You should have received a copy of the GNU Affero General Public License
// along with this program. If not, see <http://www.gnu.org/licenses/>.

require_once('graphite.php');
require_once('arc/ARC2.php');

/**
 * Implements WebID Authentication
 * seeAlso https://foafssl.org/srv/idp
 *
 * If successfull, it redirects the user to the Service Provider's URI
 * adding information like webid, timestamp (signing them with the IdP's
 * private key). 
 * Ex. for Service Provider http://webid.fcns.eu it will return:
 * http://webid.fcns.eu/index.php?webid=$webid&ts=$timeStamp&sig=$URLSignature
 */
class WebIDauth {
    public $err     = array(); // will hold our errors for diagnostics
    private $ts     = NULL; // timestamp in W3C XML format
    private $cert   = NULL; // php array with the contents of the certificate
    private $cert_pem    = NULL; // certificate in pem format
    private $modulus = NULL; // modulus component of the public key
    private $exponent = NULL; // exponent component of the public key
    private $is_bnode = false; // if the modulus is expressed as a bnode
    private $webid = array(); // webid URIs
    private $claim_id = NULL; // the webid for which we have a match
    private $cert_txt = NULL; // textual representation of the certificate
    private $issuer  = NULL; // issuer uri
    private $tmp    = NULL; // location to store temporary files needed by openssl 
    private $code    = NULL; // will hold error codes
    private $verified = NULL; // TLS client private key verification

    private $privKey = NULL; // private key of the IdP's SSL certificate (this server)

	const parseError = "Cannot parse WebID";

	const nocert = "No certificates installed in the client's browser";
    
    const certNoOwnership = "No ownership! Could not verify that the client certificate's public key matches their private key";

    const certExpired = "The certificate has expired";
    
    const noVerifiedWebId = "WebId does not match the certificate";
    
    const noURI = "No WebID URIs found in the provided certificate";
    
    const noWebId = "No identity found for existing WebID";
    
    const IdPError = "Other error(s) in the IdP setup. Please warn the IdP administrator";

    /** 
     * Initialize the variables and perfom sanity checks
     * "/etc/apache2/keys/ssl-cert-rena.key"
     * @return boolean
     */
    public function __construct($certificate = NULL,
                                $issuer = NULL,
                                $tmp = NULL,
                                $verified = NULL,
                                $privKey = NULL
                                )
    {
        $this->ts = date("Y-m-dTH:i:sP", time());
    
        $this->verified = $verified;
    
    
        // check first if we can write in the temp dir
        if ($tmp) {
            $this->tmp = $tmp;
            // test if we can write to this dir
            $tmpfile = $this->tmp . "/CRT" . md5(time().rand());
            $handle = fopen($tmpfile, "w") or die("[Runtime Error] Cannot write file to temporary dir (" . $tmpfile . ")!");
      	    fclose($handle);
      	    unlink($tmpfile);
        } else {
            $this->err[] = "[Runtime Error] You have to provide a location to store temporary files!";
        }        
        
        // check if we have openssl installed 
        $command = "openssl version";
        $output = shell_exec($command);
        if (preg_match("/command not found/", $output) == 1) {
            $this->err[] = "[Runtime Error] OpenSSL may not be installed on your host!";
        }
        
        // process certificate contents 
        if ($certificate) {
            // set the certificate in pem format
            $this->cert_pem = $certificate;

            // get the modulus from the browser certificate (ugly hack)
            $tmpCRTname = $this->tmp . "/CRT" . md5(time().rand());
            // write the certificate into the temporary file
            $handle = fopen($tmpCRTname, "w") or die("[Runtime Error] Cannot open temporary file to store the client's certificate!");
            fwrite($handle, $this->cert_pem);
            fclose($handle);

            // get the hexa representation of the modulus
          	$command = "openssl x509 -in " . $tmpCRTname . " -modulus -noout";
          	$output = explode('=', shell_exec($command));
            $this->modulus = preg_replace('/\s+/', '', strtolower($output[1]));

            // get the full contents of the certificate
            $command = "openssl x509 -in " . $tmpCRTname . " -noout -text";
            $this->cert_txt = shell_exec($command);
            
            // create a php array with the contents of the certificate
            $this->cert = openssl_x509_parse(openssl_x509_read($this->cert_pem));

            if (!$this->cert) {
                $this->err[] = $this->nocert;
                $this->code = "nocert";
                $this->data = $this->retErr($this->code);
            }
            
            // get subjectAltName from certificate (there might be more stuff in AltName)
            $alt = explode(', ', $this->cert['extensions']['subjectAltName']);
            // find the webid URI
            foreach ($alt as $val) {
                if (strstr($val, 'URI:')) {
                    $webid = explode('URI:', $val);
                    $this->webid[] = $webid[1];
                }
            }
                                
          	// delete the temporary certificate file
           	unlink($tmpCRTname);
        } else {
            $this->err[] = "[Client Error] You have to provide a certificate!";
        }

        // process issuer
        if ($issuer)
            $this->issuer = $issuer;
             
        // load private key
        if ($privKey) {
            // check if we can open location and then read key
            $fp = fopen($privKey, "r") or die("[Runtime Error] Cannot open privte key file for the server's SSL certificate!");
            $this->privKey = fread($fp, 8192);
            fclose($fp);
        } else {
            $this->err[] = "[Runtime Error] You have to provide the location of the server SSL certificate's private key!";
        }
		
        // check if everything is good
        if (sizeof($this->err)) {
            $this->getErr();
            return false;
        } else {
            return true;
        }
    }
    
    /**
     * Return the error URL
     * @code = nocert, noVerifiedWebId, noWebId, IdPError
     *
     * @return string
     */
    function retErr($code)
    {
        return $this->issuer . "?error=" . $code;
    }
    
    /**
     * Return the errors
     *
     * @return array
     */
    function getErr()
    {
        $ret = "";
        foreach ($this->err as $error) {
            echo "Error: " . $error . "<br/>";
        }
        return $ret;
    }
    
    /**
     * DANGEROUS:returns the object itself.
     * Sould only be used for debugging!
     */
    function dumpVars()
    {
        return $this;
    }

    /**
     * Display an extensive overview of the whole authentication process
     * @return html data
     */
    public function display()
    {
        // display all WebIDs in the certificate
		echo "<p>&nbsp;</p>\n";
        echo "Your certificate contains the following WebIDs:<br/>\n";
        echo "<ul>\n";
        foreach ($this->webid as $webid)
            echo "<li>" . $webid . "</li>\n";
        echo "</ul><br/>\n";

        // display the WebID that got a match
        echo "The WebID URI used to claim your identity is:<br/>\n";
        echo "<ul>\n";
        echo "  <li>" . $this->claim_id . " (your claim was ";
        echo isset($this->claim_id)?'<font color="green">SUCCESSFUL</font>!)':'<font color="red">UNSUCCESSFUL</font>!)';        
        echo "  </li>\n";
        echo "</ul><br/>\n";
        
        // print the url suffix
        echo "The WebID URL suffix (to be signed) for your service provider is:<br/>\n";
        echo "<ul>\n";
        echo "  <li>" . urldecode($this->data) . "</li>\n";
        echo "</ul><br/>\n";

        if (sizeof($this->webid) > 1)         
            echo "<font color=\"orange\">WARNING:</font> Your modulus has more than one relation to a hexadecimal string. ";
            echo "Unless both of those strings map to the same number, your identification experience will vary across clients.<br/><br/>\n";
        // warn if we have a bnode modulus
        if ($this->is_bnode)
            echo "<font color=\"orange\">WARNING:</font> your modulus is a blank node. The newer specification requires this to be a literal.<br/><br/>\n";
        
        // print errors if any
        if (sizeof($this->err)) {
            echo "Error code:<br/>\n";
            echo "<ul>\n";
            echo "  <li><font color=\"red\">" . $this->code . "</font> " . $this->getErr() . "</li>\n";
            echo "</ul><br/>\n";
        }
        
        // print the certificate in it's raw format
        echo "<p>&nbsp;</p>\n";
        echo "<strong>Certificate in PEM format: </strong><br/>\n";
        echo "<pre>" . $this->cert_pem . "</pre><br/><br/>\n";

        // print the certificate in text format
        echo "<strong>Certificate in text format: </strong><br/>\n";
        echo "<pre>" . $this->cert_txt . "</pre><br/><br/>\n";
        
    }
  
    /** 
     * Process the request by comparing the modulus in the public key of the
     * certificate with the modulus in webid profile. If everything is ok, it
     * returns -> $authreqissuer?webid=$webid&ts=$timeStamp, else it returns
     * -> $authreqissuer?error=$errorcode
     *
     * @return boolean 
     */
   
	function processReq($verbose = NULL)
    {
        // get expiration date
        $expire = $this->cert['validTo_time_t'];
    
        if ($verbose)
            echo "<br/> * Checking ownership of certificate (public key matches private key)...\n";
        // verify client certificate using TLS
		if (($this->verified == 'SUCCESS') || ($this->verified == 'GENEROUS')) {
            if ($verbose) 
                echo "<font color=\"green\">PASSED</font> <small>(Reason: " . $this->verified . ")</small><br/>\n";
            
        } else {
            if ($verbose) 
                echo "<font color=\"red\">FAILED</font> <small>(Reason: " . $this->verified . ")</small><br/>\n";
            $this->err = $this->certNoOwnership;
            $this->code = "certNoOwnership";
            $this->data = $this->retErr($this->code);
            return false;
        }

        if ($verbose)
            echo "<br/> * Checking if certificate has expired...\n";

        $now = time();
        // do not proceed if certificate has expired
        if ($expire < $now) {
            if ($verbose) {
                echo "<font color=\"red\">FAILED</font> ";
                echo "<small>(Reason: " . date("Y-m-d H:i:s", $expire) . " &lt; " . date("Y-m-d H:i:s", $now) . ")</small><br/>\n";
            }
            $this->err = $this->certExpired;
            $this->code = "certExpired";
            $this->data = $this->retErr($this->code);
            return false;
        } else {
            if ($verbose)
                echo "<font color=\"green\">PASSED</font><br/>\n";
        }
        
        // check if we have URIs
        if (!sizeof($this->webid)) {
            if ($verbose) 
                echo "<br/> * <font color=\"red\">" . $this->noURI . "!</font><br/>\n";

            $this->err = $this->noURI;
            $this->code = "noURI";
            $this->data = $this->retErr($this->code);
            return false;
        } else {
            // list total number of webids in the certificate
            if ($verbose) 
                echo "<br/> * Found " . sizeof($this->webid) . " URIs in the certificate (a maximum of 3 will be tested).<br/>\n";
        }        
        
        // default = no match
        $match = false;
        $match_id = array();
        // try to find a match for each webid URI in the certificate
        // maximum of 3 URIs per certificate - to prevent DoS
        $i = 0;
		if (sizeof($this->webid) >= 3)
			$max = 3;
		else
			$max = sizeof($this->webid);
        while ($i < $max) {
            $webid = $this->webid[$i];

            if ($verbose) {
				$curr = $i + 1;
                echo "<br/> * Checking URI " . $curr;
                echo "<small> (" . $webid . ")</small>...<br/>\n";  
            }
            // fetch identity for webid profile 
            $graph = new Graphite();
            $graph->load($webid);
            $person = $graph->resource($webid);

            if ($verbose)
                echo "&nbsp; - Trying to fetch and process certificate(s) from webid profile...\n";

            $bnode = false;
			$identity = false;
            // parse all certificates contained in the webid document
            foreach ($graph->allOfType('http://www.w3.org/ns/auth/rsa#RSAPublicKey') as $certs) {
                $identity = $certs->get('http://www.w3.org/ns/auth/cert#identity');
 
                if ($verbose) {
					echo "<font color=\"green\">PASSED</font><br/>\n";
                    echo "&nbsp;&nbsp;&nbsp; - Testing if the client's identity matches the one in the webid...\n";
				}
                // proceed if the identity of subjectAltName matches one identity in the webid 
                if ($identity == $webid) {
                    if ($verbose)
                        echo "<font color=\"green\">PASSED</font><br/>\n";
                    // save the URI if it matches an identity
                    $match_id[] = $webid;
                    
                    // get corresponding resources for modulus and exponent
                    if (substr($certs->get('http://www.w3.org/ns/auth/rsa#modulus'), 0, 2) == '_:') {
                        $mod = $graph->resource($certs->get('http://www.w3.org/ns/auth/rsa#modulus'));
                        $hex = $mod->all('http://www.w3.org/ns/auth/cert#hex')->join(',');
                        $bnode = true;
                    } else {
                        $hex = $certs->get('http://www.w3.org/ns/auth/rsa#modulus');
                    }

                    // uglier but easier to process
                    $hex_vals = explode(',', $hex);

                    if ($verbose) {
                        echo "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;";
                        echo "Testing if the modulus representation matches the one in the webid ";
                        echo "(found <font color=\"red\">" . sizeof($hex_vals) . "</font> modulus values)...<br/>\n";
                    }
                    // go through each key and check if it matches
                    foreach ($hex_vals as $key => $hex) {
                        // clean up strings
                		$hex = preg_replace('/\s+/', '', $hex);
		
                        if ($verbose) {
                            echo "<br/>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;";
                            echo "Testing modulus: " . ($key + 1) . "/" . sizeof($hex_vals) . "...\n";
                        }
	
	        	        // check if the two modulus values match
                        if ($hex == $this->modulus) {
                            if ($verbose) {
                                echo "<font color=\"green\">PASSED</font><br/>\n";
                                echo "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;";
            					echo "WebID=" . substr($hex, 0, 15) . "......." . substr($hex, strlen($hex) - 15, 15) . "<br/>\n";
        						echo "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;";
        						echo "&nbsp;Cert&nbsp; =" . substr($this->modulus, 0, 15) . "......." . substr($this->modulus, strlen($this->modulus) - 15, 15) . "<br/>\n";
                            }                                

                            $this->data = $this->issuer . "?webid=" . urlencode($webid) . "&ts=" . urlencode($this->ts);
                            $match = true;
                            $this->claim_id = $webid;
                            $this->is_bnode = $bnode;
                            // we got a match -> exit loop
                            break;
                        } else {
                            if ($verbose) {
                                echo "<font color=\"red\">FAILED</font><br/>\n";
                                echo "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;";
            					echo "WebID=" . substr($hex, 0, 15) . "......." . substr($hex, strlen($hex) - 15, 15) . "<br/>\n";
        						echo "&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;";
        						echo "&nbsp;Cert&nbsp; =" . substr($this->modulus, 0, 15) . "......." . substr($this->modulus, strlen($this->modulus) - 15, 15) . "<br/>\n";
							}
                            continue;
                        }
                    }
                } else {
                    if ($verbose)
                        echo "<font color=\"red\">FAILED</font><br/>\n";
                }
            } // end foreach($cert)

            // failed to find an identity at the specified WebID URI 
			if (!$identity) {
				if ($verbose)
					echo "<font color=\"red\">FAILED</font><br/>\n";
			}
			// exit while loop if we have a match
			if ($match)
				break;           
 
            $i++;
        } // end while()

        // we had no match, return false          
        if (!$match) {
            if ($verbose)
                echo "<br/><font color=\"red\"> * " . $this->noVerifiedWebId . "</font><br/>\n";
            $this->err = $this->noVerifiedWebId;
            $this->code = "noVerifiedWebId";
            $this->data = $this->retErr($this->code);
            return false;
        }
        // if no identity is found, return false
        if (!sizeof($match_id)) {
            if ($verbose)
                echo "<br/><font color=\"red\"> * " . $this->noWebId . "</font><br/>\n";
            $this->err = $this->noWebId;
            $this->code = "noWebId";
            $this->data = $this->retErr($this->code);
            return false;
        }
        
        // otherwise all is good
        if ($verbose)
            echo "<br/><font color=\"green\"> * All tests have passed!</font><br/>\n";
        return true;
    } // end function
 
    /** 
     * Redirect user to the Service Provider's page, then exit.
     * The header location is signed with the private key of the IdP 
     */
    public function redirect()
    {
        // get private key object
        $pkey = openssl_get_privatekey($this->privKey);

        // sign data
        openssl_sign($this->data, $signature, $pkey);

        // free the key from memory
        openssl_free_key($pkey);

        // redirect user back to issuer page
        header("Location: " . $this->data . "&sig=" . urlencode(base64_encode($signature)) . "&referer=https://" . $_SERVER["SERVER_NAME"]);
        exit;
    }

} // end class

?>
