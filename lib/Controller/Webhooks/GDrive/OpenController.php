<?php 

namespace Webhooks\GDrive  ;

use Bootstrap ; 
use Log;
use API\V2\KleinController ;
use OauthClient ; 
use Google_Service_Drive ; 
use Google_Http_Request ;
use Utils ;
use INIT ; 
use ConversionHandler ; 

class OpenController extends KleinController {

    private $file_name; 
    private $source_lang = 'en-US';  // <-- TODO: check why en-GB breaks everything
    private $target_lang = 'it-IT';
    private $seg_rule = null; 

    public function open() {

        // TODO: assuming a user is logged in for now
        // start session 
        Bootstrap::sessionStart(); 

        // check $_SESSION['upload_session']; 
        //
        $dao = new \Users_UserDao( \Database::obtain() ); 
        $user = $dao->getByUid( $_SESSION['uid'] ); 

        $guid = Utils::create_guid(); 
        setcookie( "upload_session", $guid, time() + 86400, '/' );

        // set a new cookie 

        $token = $user->oauth_access_token ; 

        $state = json_decode( $this->request->param('state'), TRUE );

        \Log::doLog( $state );

        $client = OauthClient::getInstance()->getClient();
        $client->setAccessToken( $token ); 
        $service = new Google_Service_Drive( $client );

        // TODO: handle token expired HERE 
        // \/\/\/\/\/\/\/\/\/\/\/\/\/\/\/
        //
        if ( array_key_exists( 'ids', $state) ) {
            $fileId = $state['ids'][0];
            $file = $service->files->get($fileId);
            $downloadUrl = $file->getDownloadUrl();
        }
        else if ( array_key_exists('exportIds', $state) ) {
            // forge a request to the APIs V3 to get export download URL
            $fileId = $state['exportIds'][0];
            $mime = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
            $file = $service->files->get($fileId);
            $links = $file->getExportLinks() ;
            $downloadUrl = $links[ $mime ];
        }
        else {
            throw new Exception( " no ids or export ids found ");
        }

        // Save in session to allow createProject to insert
        // the database record representing the file along with its
        // google drive ids.
        $_SESSION['google_drive_file_id'] = $fileId ;

        if ($downloadUrl) {
            $this->file_name = $file->getTitle(); 

            $request = new \Google_Http_Request($downloadUrl, 'GET', null, null);
            $httpRequest = $service->getClient()->getAuth()->authenticatedRequest($request);
            if ($httpRequest->getResponseHttpCode() == 200) {
                $body =  $httpRequest->getResponseBody();
                $directory = Utils::uploadDirFromSessionCookie( $guid );
                mkdir($directory, 0755, true);

                $file_path = Utils::uploadDirFromSessionCookie( $guid, $this->file_name ); 
                $saved = file_put_contents( $file_path, $httpRequest->getResponseBody() ); 

                if ( $saved !== FALSE ) {
                    $this->correctSourceTargetLang();
                    $this->doConversion( $guid ); 
                    $_SESSION['pre_loaded_file'] = $this->file_name ;

                    header("Location: /?preupload=1", true, 302);
                    exit; 
                }

            } else {
                // An error occurred.
                return null;
            }
        } else {
            // The file doesn't have any content stored on Drive.
            return null;
        }

    }

    protected function afterConstruct() {

    }

    private function doConversion( $cookieDir ) {

        $intDir         = INIT::$UPLOAD_REPOSITORY . 
            DIRECTORY_SEPARATOR . $cookieDir;

        $errDir         = INIT::$STORAGE_DIR .
            DIRECTORY_SEPARATOR .
            'conversion_errors'  . 
            DIRECTORY_SEPARATOR . $cookieDir;

        $conversionHandler = new ConversionHandler();
        $conversionHandler->setFileName( $this->file_name );
        $conversionHandler->setSourceLang( $this->source_lang );
        $conversionHandler->setTargetLang( $this->target_lang );
        $conversionHandler->setSegmentationRule( $this->seg_rule );
        $conversionHandler->setCookieDir( $cookieDir );
        $conversionHandler->setIntDir( $intDir );
        $conversionHandler->setErrDir( $errDir ); 

        $conversionHandler->doAction();

        return $conversionHandler->getResult();
    }

    private function correctSourceTargetLang() {
        $defaultSourceLang = 'en-US';
        $defaultTargetLang = 'fr-FR';
        
        if ( isset ( $_COOKIE[ "sourceLang" ] ) ) {
            if( $_COOKIE[ "sourceLang" ] == "_EMPTY_" ) {
                $this->source_lang = $defaultSourceLang;
            } else {
                $sourceLangHistory   = $_COOKIE[ "sourceLang" ];
                $sourceLangAr        = explode( '||', urldecode( $sourceLangHistory ) );
                
                if(count( $sourceLangAr ) > 0) {
                    $this->source_lang = $sourceLangAr[0];
                } else {
                    $this->source_lang = $defaultSourceLang;
                }
            }
        } else {
            setcookie( "sourceLang", "_EMPTY_", time() + ( 86400 * 365 ) );
            
            $this->source_lang = $defaultSourceLang;
        }
        
        if ( isset ( $_COOKIE[ "targetLang" ] ) ) {
            if( $_COOKIE[ "targetLang" ] == "_EMPTY_" ) {
                $this->target_lang = $defaultTargetLang;
            } else {
                $targetLangHistory   = $_COOKIE[ "targetLang" ];
                $targetLangAr        = explode( '||', urldecode( $targetLangHistory ) );
                
                if(count( $targetLangAr ) > 0) {
                    $this->target_lang = $targetLangAr[0];
                } else {
                    $this->target_lang = $defaultTargetLang;
                }
            }
        } else {
            setcookie( "targetLang", "_EMPTY_", time() + ( 86400 * 365 ) );
            
            $this->target_lang = $defaultTargetLang;
        }
    }
    
}
