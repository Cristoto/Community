<?php
/**
 * This file is part of Community plugin for FacturaScripts.
 * Copyright (C) 2018 Carlos Garcia Gomez <carlos@facturascripts.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */
namespace FacturaScripts\Plugins\Community\Controller;

use FacturaScripts\Core\App\AppSettings;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Plugins\Community\Model\License;
use FacturaScripts\Plugins\Community\Model\WebProject;
use FacturaScripts\Plugins\Community\Model\WebTeamLog;
use FacturaScripts\Plugins\webportal\Lib\WebPortal\EditSectionController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Description of ViewPlugin
 *
 * @author Carlos García Gómez <carlos@facturascripts.com>
 */
class ViewPlugin extends EditSectionController
{

    /**
     * The project related to this plugin.
     *
     * @var WebProject
     */
    protected $project;

    /**
     * Returns true if contact can edit this plugin.
     *
     * @return bool
     */
    public function contactCanEdit()
    {
        if ($this->user) {
            return true;
        }

        if (empty($this->contact)) {
            return false;
        }

        $project = $this->getMainModel();
        return ($this->contact->idcontacto === $project->idcontacto);
    }

    /**
     * 
     * @return bool
     */
    public function contactCanSee()
    {
        return true;
    }

    /**
     * 
     * @param string $licenseCode
     *
     * @return License
     */
    public function getLicense($licenseCode)
    {
        $license = new License();
        $license->loadFromCode($licenseCode);
        return $license;
    }

    /**
     * 
     * @return WebProject
     */
    public function getMainModel()
    {
        if (isset($this->project)) {
            return $this->project;
        }

        $project = new WebProject();
        $uri = explode('/', $this->uri);
        $project->loadFromCode('', [new DataBaseWhere('name', end($uri))]);
        return $project;
    }

    /**
     * 
     * @param string $date
     *
     * @return bool
     */
    public function isDateOld($date)
    {
        return strtotime($date) < strtotime('-9 months');
    }

    /**
     * Returns true if we can edit this model object.
     *
     * @param object $model
     *
     * @return bool
     */
    protected function checkModelSecurity($model)
    {
        if (!$this->contactCanEdit()) {
            return false;
        }

        $project = $this->getMainModel();
        switch ($model->modelClassName()) {
            case 'WebBuild':
                return $model->idproject == $project->primaryColumnValue();

            case 'WebProject':
                return $model->primaryColumnValue() == $project->primaryColumnValue();

            default:
                return parent::checkModelSecurity($model);
        }
    }

    /**
     * Load sections to the view.
     */
    protected function createSections()
    {
        $this->fixedSection();
        $this->addHtmlSection('plugin', 'plugin', 'Section/Plugin');
        $project = $this->getMainModel();
        $this->addNavigationLink($project->url('public-list'), $this->i18n->trans('plugins'));
        $this->addNavigationLink($project->url('public-list') . '?activetab=ListWebProject', '2018');

        $this->addListSection('ListWebDocPage', 'WebDocPage', 'documentation', 'fas fa-book');
        $this->sections['ListWebDocPage']->template = 'Section/Documentation.html.twig';

        /// admin
        if ($this->contactCanEdit()) {
            $this->addEditSection('EditWebProject', 'WebProject', 'edit', 'fas fa-edit', 'admin');
            $this->addEditListSection('EditWebBuild', 'WebBuild', 'builds', 'fas fa-file-archive', 'admin');
        }
    }

    protected function deleteAction()
    {
        $return = parent::deleteAction();
        if ($return && $this->active === 'EditWebProject') {
            /// adds delete plugin message to team log
            $uri = explode('/', $this->uri);
            $this->saveTeamLog('Deleted plugin ' . end($uri), '');
        } elseif ($return && $this->active === 'EditWebBuild') {
            $this->sections[$this->active]->model->clear();
        }

        return $return;
    }

    protected function insertAction()
    {
        $return = parent::insertAction();
        if ($return && $this->active === 'EditWebBuild') {
            /// adds new plugin version message to team log
            $plugin = $this->getMainModel();
            $version = $this->request->request->get('version', $plugin->version);
            $this->saveTeamLog('Uploaded plugin ' . $plugin->name . ' v' . $version, $plugin->url('public'));
        } elseif (false === $return && $this->active === 'EditWebBuild') {
            $this->sections[$this->active]->model->clear();
        }

        return $return;
    }

    /**
     * Load section data procedure
     *
     * @param string $sectionName
     */
    protected function loadData(string $sectionName)
    {
        $project = $this->getMainModel();
        switch ($sectionName) {
            case 'EditWebBuild':
                $where = [new DataBaseWhere('idproject', $project->idproject)];
                $this->sections[$sectionName]->loadData('', $where, ['version' => 'DESC']);
                $this->updatePluginVersion($sectionName);
                break;

            case 'EditWebProject':
                $this->sections[$sectionName]->loadData($project->primaryColumnValue());
                break;

            case 'ListWebDocPage':
                $where = [
                    new DataBaseWhere('idproject', $project->idproject),
                    new DataBaseWhere('idparent', null, 'IS'),
                ];
                $this->sections[$sectionName]->loadData('', $where);
                break;

            case 'plugin':
                $this->loadPlugin();
                break;
        }
    }

    /**
     * Load the plugin data.
     */
    protected function loadPlugin()
    {
        $this->project = $this->getMainModel();
        if ($this->project->exists() && $this->project->plugin) {
            $this->title = 'Plugin ' . $this->project->name;
            $this->description = $this->project->description();

            $ipAddress = is_null($this->request->getClientIp()) ? '::1' : $this->request->getClientIp();
            $this->project->increaseVisitCount($ipAddress);
            return;
        }

        $this->miniLog->alert($this->i18n->trans('no-data'));
        $this->response->setStatusCode(Response::HTTP_NOT_FOUND);
        $this->webPage->noindex = true;
        $this->setTemplate('Master/Portal404');
    }

    /**
     * 
     * @param string $description
     * @param string $link
     *
     * @return bool
     */
    protected function saveTeamLog($description, $link)
    {
        $teamLog = new WebTeamLog();
        $teamLog->description = $description;
        $teamLog->idcontacto = $this->contact->idcontacto;
        $teamLog->idteam = AppSettings::get('community', 'idteamdev');
        $teamLog->link = $link;
        return $teamLog->save();
    }

    /**
     * Updates plugin version with the top build version.
     *
     * @param string $sectionName
     */
    protected function updatePluginVersion(string $sectionName)
    {
        $version = 0;
        $lastmod = null;
        $downloads = 0;

        $plugin = $this->getMainModel();
        foreach ($this->sections[$sectionName]->cursor as $model) {
            $downloads += $model->downloads;
            if ($model->version > $version) {
                $version = $model->version;
                $lastmod = $model->date;
            }
        }

        if ($version != $plugin->version || $downloads != $plugin->downloads) {
            $plugin->downloads = $downloads;
            $plugin->version = $version;
            $plugin->lastmod = $lastmod;
            $plugin->save();
        }

        $this->sections['EditWebBuild']->model->version = $version + 0.1;
    }
}
