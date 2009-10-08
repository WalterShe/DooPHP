<?php
/**
 * DooAuth class, manage user authentication
 * @author Gustavo Seip <glseip@gmail.com>
 * @license http://www.doophp.com/license
 * @version $Id: DooAuth.php 2009-10-06 15:10:12
 * @package doo.auth
 * @since 1.3
 *
 */

class DooAuth {
    /**
     * HIGH security level
     * @var integer
     */
    const HIGH_LEVEL = 1;
    /**
     * MEDIUM security level
     * @var integer
     */
    const MEDIUM_LEVEL = 2;
    /**
     * LOW security level
     * @var integer
     */
    const LOW_LEVEL = 3;
    /**
     * Discarded form indicator
     * @var integer
     */
    const FORM_DISCARDED = 1;
    /**
     * Timeout form indicator
     * @var integer
     */
    const FORM_TIMEOUT = 2;
    /**
     * DooSession instance
     * @var DooSession
     */
    protected $appSession;
    /**
     * Application name
     * @var string
     */
    protected $appName;
    /**
     * A random string for hashing
     * @var string
     */
    protected $salt;
    /**
     * maximum time for downtime
     * @var integer
     */
    protected $authSessionExpire = 60; //time in seconds
    /**
     * Security level
     * @var integer
     */
    protected $securityLevel;
    /**
     * Maximun time for form timeout
     * @var integer
     */
    protected $authPostWait = 60; //time frame - in seconds
    /**
     * Minimun time for form timeout
     * @var integer
     */
    protected $authPostExpire = 20; //time frame - in seconds
    /**
     * Session authentication data
     * @var array
     */
    protected $authData;
    /**
     * Indicator for valid authetication
     * @var boolean
     */
    protected $isValid = false;

    /**
     * Constructor - returns an instance object of DooAuth
     */
    public function __construct($appName) {
        $this->setApplicationName($appName);
    }

    /**
     * Start auth component
     */
    public function start() {
        $this->appSession = Doo::session($this->getApplicationName());
        $this->authData = $this->appSession->AuthData;
        $this->validate();
    }

    /**
     * Finalize autentication
     */
    public function finalize() {
        if (!$this->appSession->isDestroyed())
            $this->appSession->destroy();
    }

    /**
     * Set auth data for user session
     * @param <String> User name
     * @param <Mixed> User group
     */
    public function setData($username, $group=FALSE) {
        $this->appSession->AuthData = array();
        $this->appSession->AuthData['_username'] = $username;
        $this->appSession->AuthData['_group'] = $group;
        $this->appSession->AuthData['_securityLevel'] = $this->getSecurityLevel();
        $this->appSession->AuthData['_time'] = time();
        switch ($this->securityLevel) {
            case self::HIGH_LEVEL:
                $this->appSession->AuthData['_initialized'] = true;
                $this->appSession->AuthData['_fingerprint'] = md5($_SERVER['HTTP_USER_AGENT'].$this->getSalt());
                session_regenerate_id();
                $this->appSession->AuthData['_id'] = md5($this->appSession->getId());
                $this->appSession->AuthData['_authSessionExpire'] = $this->getSessionExpire() * 15;
                $this->appSession->AuthData['_authPostWait'] = $this->getPostWait() * 11; //~25% of authSessionExpire
                $this->appSession->AuthData['_authPostExpire'] = $this->getPostExpire();
                break;
            case self::MEDIUM_LEVEL:
                $this->appSession->AuthData['_initialized'] = true;
                $this->appSession->AuthData['_fingerprint'] = md5($_SERVER['HTTP_USER_AGENT'].$this->getSalt());
                $this->appSession->AuthData['_authSessionExpire'] = $this->getSessionExpire() * 120;
                $this->appSession->AuthData['_authPostWait'] = $this->getPostWait() * 60; //~50% of authSessionExpire
                $this->appSession->AuthData['_authPostExpire'] = $this->getPostExpire();
                break;
            case self::LOW_LEVEL:
                $this->appSession->AuthData['_initialized'] = true;
                $this->appSession->AuthData['_authSessionExpire'] = $this->getSessionExpire() * 360;
                $this->appSession->AuthData['_authPostWait'] = $this->getPostWait() * 90; //~75% of authSessionExpire
                $this->appSession->AuthData['_authPostExpire'] = $this->getPostExpire();
                break;
            default:
                break;
        }
    }

    /**
     * Validate authentication data
     * @see http://phpsec.org/projects/guide/4.html
     * @see http://www.serversidemagazine.com/php/session-hijacking
     * @return <Boolean>
     */
    public function validate() {
        if (isset ($this->authData)) {
            if (    ($this->_securityLevel==self::LOW_LEVEL && ($this->_initialized || isset ($this->_username) || ((time()-$this->_time) <= $this->_authSessionExpire))) || //LOW_LEVEL
                    (($this->_securityLevel==self::MEDIUM_LEVEL || $this->_securityLevel==self::HIGH_LEVEL) //MEDIUM_LEVEL
                         && $this->_fingerprint == md5($_SERVER['HTTP_USER_AGENT'].$this->getSalt())) ||
                    ($this->_securityLevel==self::HIGH_LEVEL && $this->_id==md5($this->appSession->getId())) ) { //HIGH_LEVEL
                $this->_time = time();
                $this->isValid = true;
            }
        } else
            $this->isValid = false;
    }

    /**
     * Get token for security purpose (secure forms, etc)
     * @see http://www.serversidemagazine.com/php/php-security-measures-against-csrf-attacks
     * @see http://www.serversidemagazine.com/php/session-hijacking
     * @return <Mixed>
     */
    public function securityToken() {
        if ($this->isValid()) {
            $this->appSession->AuthData['_formToken'] = uniqid(rand(), true);
            return $this->_formToken;
        }
        return false;
    }

    /**
     * Validate form with security token
     * @see http://www.serversidemagazine.com/php/php-security-measures-against-csrf-attacks
     * @return <Mixed>
     */
    public function validateForm($receivedToken) {
        if ($this->isValid && isset($receivedToken)) {
            if ($this->_formToken!=$receivedToken)
                return false;
            $time = time() - $this->_time;
            if ($time < $this->_authPostExpire)
                return self::$FORM_DISCARDED;
            elseif ($time > $this->_authPostWait)
                return self::$FORM_TIMEOUT;
            return true;
        }
        return false;
    }

    /////////// SETTERs & GETTERs ////////////
    public function setApplicationName($appName) {
        if (!isset ($appName))
            throw new DooAuthException("Application name cannot be empty");
        $this->appName = $appName;
    }
    public function getApplicationName() {
        if (!isset ($this->appName))
            throw new DooAuthException("Application name not defined");
        return $this->appName;
    }
    public function setSalt($salt) {
        if (!isset ($salt))
            throw new DooAuthException("Salt cannot be empty");
        $this->salt = $salt;
    }
    public function getSalt() {
        if (!isset ($this->salt))
            throw new DooAuthException("Salt not defined");
        return $this->salt;
    }
    public function setSecurityLevel($securityLevel) {
        if (!isset ($securityLevel))
            throw new DooAuthException("Security level cannot be empty");
        $this->securityLevel = $securityLevel;
    }
    public function getSecurityLevel() {
        if (!isset ($this->securityLevel))
            throw new DooAuthException("Security level not defined");
        return $this->securityLevel;
    }
    public function setSessionExpire($sessionExpire) {
        if (!isset ($sessionExpire))
            throw new DooAuthException("Session expire cannot be empty");
        $this->authSessionExpire = $sessionExpire;
    }
    public function getSessionExpire() {
        if (!isset ($this->authSessionExpire))
            throw new DooAuthException("Session expire not defined");
        return $this->authSessionExpire;
    }
    public function setPostExpire($postExpire) {
        if (!isset ($postExpire))
            throw new DooAuthException("Post expire cannot be empty");
        $this->authPostExpire = $postExpire;
    }
    public function getPostExpire() {
        if (!isset ($this->authPostExpire))
            throw new DooAuthException("Post expire not defined");
        return $this->authPostExpire;
    }
    public function setPostWait($postWait) {
        if (!isset ($postWait))
            throw new DooAuthException("Post wait cannot be empty");
        $this->authPostWait = $postWait;
    }
    public function getPostWait() {
        if (!isset ($this->authPostWait))
            throw new DooAuthException("Post wait not defined");
        return $this->authPostWait;
    }
    public function isValid() {
        return $this->isValid;
    }

    ////////////////// Magic ////////////////////
    public function  __set($name,  $value) {
        if (!isset ($this->authData))
            throw new DooAuthException("authentication data not initialized");
        return $this->authData[$name] = $value;
    }
    public function  __get($name) {
        if (!isset ($this->authData))
            throw new DooAuthException("authentication data not initialized");
        return $this->authData[$name];
    }
}

class DooAuthException extends Exception {
    
}
?>
