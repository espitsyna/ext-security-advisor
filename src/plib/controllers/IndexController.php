<?php

class IndexController extends pm_Controller_Action
{
    protected $_accessLevel = 'admin';

    public function init()
    {
        parent::init();

        $this->view->pageTitle = $this->lmsg('pageTitle');

        $this->view->tabs = [
            [
                'title' => $this->lmsg('tabs.domains')
                    . $this->_getBadge(Modules_SecurityWizard_Letsencrypt::countInsecureDomains()),
                'action' => 'domain-list',
            ],
            [
                'title' => $this->lmsg('tabs.wordpress')
                    . $this->_getBadge(Modules_SecurityWizard_Helper_WordPress::getNotSecureCount()),
                'action' => 'wordpress-list',
            ],
            [
                'title' => $this->lmsg('tabs.system'),
                'action' => 'system',
            ],
        ];
    }

    private function _getBadge($count)
    {
        if ($count > 0) {
            return ' <span class="badge-new">' . $count . '</span>';
        }
        return '';
    }

    public function indexAction()
    {
        $this->_forward('domain-list');
    }

    public function domainListAction()
    {
        $this->view->list = $this->_getDomainsList();
    }

    public function domainListDataAction()
    {
        $this->_helper->json($this->_getDomainsList()->fetchData());
    }

    private function _getDomainsList()
    {
        $list = new Modules_SecurityWizard_View_List_Domains($this->view, $this->_request);
        $list->setDataUrl(['action' => 'domain-list-data']);
        return $list;
    }

    public function letsencryptAction()
    {
        if (!$this->_request->isPost()) {
            throw new pm_Exception('Post request is required');
        }
        $successDomains = [];
        $messages = [];
        foreach ((array)$this->_getParam('ids') as $domainId) {
            try {
                $domain = new pm_Domain($domainId);
                Modules_SecurityWizard_Letsencrypt::run($domain->getName());
                $successDomains[] = $domain->getName();
            } catch (pm_Exception $e) {
                $messages[] = ['status' => 'error', 'content' => $this->view->escape($e->getMessage())];
            }
        }

        if ($successDomains) {
            $domainLinks = implode(', ', array_map(function ($domainName) {
                return "<a href='https://{$domainName}' target='_blank'>{$domainName}</a>";
            }, $successDomains));
            $successMessage = $this->lmsg('controllers.letsencrypt.successMsg', ['domains' => $domainLinks]);
            $messages[] = ['status' => 'info', 'content' => $successMessage];
            $status = 'success';
        } else {
            $status = 'error';
        }
        $this->_helper->json(['status' => $status, 'statusMessages' => $messages]);
    }

    public function installLetsencryptAction()
    {
        if (!$this->_request->isPost()) {
            throw new pm_Exception('Post request is required');
        }
        Modules_SecurityWizard_Extension::install(Modules_SecurityWizard_Letsencrypt::INSTALL_URL);
        $this->_redirect('index/domain-list');
    }

    public function wordpressListAction()
    {
        $this->view->list = $this->_getWordpressList();
    }

    public function wordpressListDataAction()
    {
        $this->_helper->json($this->_getWordpressList()->fetchData());
    }

    private function _getWordpressList()
    {
        $list = new Modules_SecurityWizard_View_List_Wordpress($this->view, $this->_request);
        $list->setDataUrl(['action' => 'wordpress-list-data']);
        return $list;
    }

    public function switchWordpressToHttpsAction()
    {
        if (!$this->_request->isPost()) {
            throw new pm_Exception('Post request is required');
        }

        $failures = [];
        foreach ((array)$this->_getParam('ids') as $wpId) {
            try {
                // TODO: check access
                Modules_SecurityWizard_Helper_WordPress::switchToHttps($wpId);
            } catch (pm_Exception $e) {
                $failures[] = $e->getMessage();
            }
        }

        if (empty($failures)) {
            $this->_status->addInfo($this->lmsg('controllers.switchWordpressToHttps.successMsg'));
        } else {
            $message = $this->lmsg('controllers.switchWordpressToHttps.errorMsg') . '<br>';
            $message .= implode('<br>', array_map([$this->view, 'escape'], $failures));
            $this->_status->addError($message, true);
        }

        $this->_helper->json([
            'status' => empty($failures) ? 'success' : 'error',
            'redirect' => pm_Context::getActionUrl('index', 'wordpress-list'),
        ]);
    }

    public function systemAction()
    {
        $returnUrl = pm_Context::getActionUrl('index', 'system');

        // handle post request
        if ($this->getRequest()->isPost()) {
            // enable http2
            if (isset($_POST['btn_http2_enable'])) {
                list($code, $msgs) = $this->_enable_http2('enable');
                if ($code != 0) {
                    foreach ($msgs as $msg) {
                        $this->_status->addMessage('error', $msg);
                    }
                }
                // disable http2
            } elseif (isset($_POST['btn_http2_disable'])) {
                list($code, $msgs) = $this->_enable_http2('disable');
                if ($code != 0) {
                    foreach ($msgs as $msg) {
                        $this->_status->addMessage('error', $msg);
                    }
                }
                // install datagrid scanner
            } elseif (isset($_POST['btn_datagrid_install'])) {
                $dg = new Modules_SecurityWizard_Datagrid();
                $dg->install();
                // install patchman
            } elseif (isset($_POST['btn_patchman_install'])) {
                $pm = new Modules_SecurityWizard_Patchman();
                $pm->install();
            }
            //return $this->_helper->json(['redirect' => $returnUrl]);  DOES NOT WORK
            return $this->_redirect('/index/system/');
        }
        $base_url = pm_Context::getBaseUrl();
        // set secure panel state
        if (Modules_SecurityWizard_Helper_PanelCertificate::isPanelSecured()) {
            $secure_panel_state   = '<img src="' . $base_url . '/images/icon-ready.png" width="24px" height="24px" />';
            $secure_panel_content = $this->lmsg('controllers.system.panelSecured');
        } else {
            $secure_panel_state   = '<img src="' . $base_url . '/images/icon-not-ready.png" width="24px" height="24px" />';
            $secure_panel_content = '<a href="' . pm_Context::getActionUrl('index', 'secure-panel') . '">' . $this->lmsg('controllers.system.panelNotSecured') . '</a>';
        }
        // set http2 state
        if ($this->_http2_enabled()) {
            $http2_state   = '<img src="' . $base_url . '/images/icon-ready.png" width="24px" height="24px" />';
            $http2_content = 'HTTP2 is enabled';
        } else {
            $http2_state   = '<img src="' . $base_url . '/images/icon-not-ready.png" width="24px" height="24px" />';
            $http2_content = '<input type="submit" name="btn_http2_enable" value="Enable HTTP2" class="secw-link-button" onclick="show_busy(\'secw-http2-state\');" />';
        }
        // set datagrid state
        $dg = new Modules_SecurityWizard_Datagrid();
        if ($dg->isInstalled()) {
            if ($dg->isActive()) {
                $datagrid_state = '<img src="' . $base_url . '/images/icon-ready.png" width="24px" height="24px" />';
                $datagrid_content = '<a href="/modules/dgri">Datagrid reliability and vulnerability scanner</a>';
                /*
                // get eval results from datagrid
                $res = $dg->run('extended');
                // dbg:  $this->_status->addMessage('info', $res);
                try {
                    $evj = json_encode($res);
                    $ev = json_decode($evj, true);
                } catch (Exception $e) {
                    // ignore
                }
                */
            } else {
                $datagrid_state   = '<img src="' . $base_url . '/images/icon-partial.png" width="24px" height="24px" />';
                $datagrid_content = '<a href="/modules/dgri">Activate the Datagrid reliability and vulnerability scanner</a>';
            }
        } else {
            $datagrid_state   = '<img src="' . $base_url . '/images/icon-not-ready.png" width="24px" height="24px" />';
            $datagrid_content = '<input type="submit" name="btn_datagrid_install" value="Install the Datagrid reliability and vulnerability scanner" class="secw-link-button" onclick="show_busy(\'secw-datagrid-state\');" />';
        }
        // set patchman state
        $pm = new Modules_SecurityWizard_Patchman();
        if ($pm->isInstalled()) {
            if ($pm->isActive()) {
                $patchman_state = '<img src="' . $base_url . '/images/icon-ready.png" width="24px" height="24px" />';
                $patchman_content = '<a href="/modules/patchmaninstaller">Patchman</a>';
            } else {
                $patchman_state   = '<img src="' . $base_url . '/images/icon-partial.png" width="24px" height="24px" />';
                $patchman_content = '<a href="/modules/patchmaninstaller">Activate Patchman</a>';
            }
        } else {
            $patchman_state   = '<img src="' . $base_url . '/images/icon-not-ready.png" width="24px" height="24px" />';
            $patchman_content = '<input type="submit" name="btn_patchman_install" value="Install Patchman" class="secw-link-button" onclick="show_busy(\'secw-patchman-state\');" />';
        }
        // set view contents:  form
        $file = pm_Context::getHtdocsDir() . '/templates/settings.php';
        $tp = new Modules_SecurityWizard_Template($file);
        $tp->set('base_url', pm_Context::getBaseUrl());
        $tp->set('secure_panel_state', $secure_panel_state);
        $tp->set('secure_panel_content', $secure_panel_content);
        $tp->set('http2_state', $http2_state);
        $tp->set('http2_content', $http2_content);
        $tp->set('datagrid_state', $datagrid_state);
        $tp->set('datagrid_content', $datagrid_content);
        $tp->set('patchman_state', $patchman_state);
        $tp->set('patchman_content', $patchman_content);
        $this->view->form = $tp->get_content();
    }

    public function securePanelAction()
    {
        $this->view->pageTitle = $this->lmsg('controllers.securePanel.pageTitle');
        $returnUrl = pm_Context::getActionUrl('index', 'system');
        $form = new Modules_SecurityWizard_View_Form_SecurePanel([
            'returnUrl' => $returnUrl
        ]);
        if ($this->_request->isPost() && $form->isValid($this->_request->getPost())) {
            try {
                $form->process();
            } catch (pm_Exception $e) {
                $this->_status->addError($e->getMessage());
                $this->_helper->json(['redirect' => $returnUrl]);
            }
            $this->_status->addInfo($this->lmsg('controllers.securePanel.save.successMsg'));
            $this->_helper->json(['redirect' => $returnUrl]);
        }
        $this->view->form = $form;
    }

    private function _enable_http2($action)
    {
        $msgs = [];

        // determine plesk bin directory
        if ( ($bin_dir = $this->_get_psa_bin()) == '') {
            $msgs[] = 'Failed to determine the Plesk bin directory';
            return array(1, $msgs);
        }

        // verify http2_pref utility is installed
        // bp:  this also verifies the Plesk version is min 12.5.30 build 28 as
        // well as the presense of nginx min 1.9.14.
        if (! file_exists($bin_dir . '/http2_pref')) {
            $msgs[] = 'The http2_pref utility is not installed';
            return array(1, $msgs);
        }

        // exec the http2_pref utility to set the preference for http2
        list($code, $msg) = $this->_set_http2_pref($bin_dir . '/http2_pref',
            $action);
        if ($code != 0) {
            $msgs[] = $msg;
            return array(1, $msgs);
        }

        //@@ FIXME can be enable or disable
        //if (! $this->_http2_enabled()) {
        //    $msgs[] = 'Failed to enable HTTP2 using http2_pref';
        //    return array(1, $msgs);
        //}

        // return success
        return array(0, $msgs);
    }


    //  set the http2 preference
    private function _set_http2_pref($util, $action)
    {
        $msg = '';
        $ret  = null;
        try {
            $ret = pm_ApiCli::callSbin('set_http2_pref.sh', array($util, $action));
            $msg = $ret['stdout'] . $ret['stderr'];
        }
        catch (pm_Exception $e) {
            $msg = $e->getMessage();
            $ret  = array('code' => 2);
        }
        return array($ret['code'], $msg);
    }


    // return the directory containing the http2_pref utility
    private function _get_psa_bin()
    {
        // get Plesk version string
        $ver_file = '/usr/local/psa/version';
        $vstr = file_get_contents($ver_file);
        if ($vstr === false) {
            return('');
        }

        // split into array
        $vers = explode(' ', $vstr);
        if (sizeof($vers) < 2) {
            return('');
            }

        // return psa bin directory by OS
        switch ($vers[1]) {
            case 'Debian':
            case 'Ubuntu':
                return '/opt/psa/bin';
                break;
            case 'CentOS':
            case 'RedHat':
            case 'CloudLinux':
                return '/usr/local/psa/bin';
                break;
        }

        // return empty string on failure
        return('');
    }


    // return true if http2 is enabled else false
    private function _http2_enabled()
    {
        $root_d = "/usr/local/psa";
        $panel_conf = "$root_d/admin/conf/panel.ini";
        $param="nginxHttp2";
        $section="webserver";

        if (! file_exists($panel_conf)) {
            return false;
        }
        $conf = parse_ini_file($panel_conf, true);
        if ($conf === false) {
            return false;
        }
        if (! isset($conf[$section]) || ! isset($conf[$section][$param]) ||
            ! $conf[$section][$param]) {
            return false;
        }

        return true;
    }


    // return true if extension is installed else false
    private function _extension_installed()
    {
        return true;
    }

}
