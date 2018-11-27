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
use FacturaScripts\Dinamic\Lib\EmailTools;
use FacturaScripts\Plugins\Community\Model\WebTeam;
use FacturaScripts\Plugins\Community\Model\WebTeamLog;
use FacturaScripts\Plugins\Community\Model\WebTeamMember;
use FacturaScripts\Plugins\webportal\Lib\WebPortal\EditSectionController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Description of EditWebTeam
 *
 * @author Carlos García Gómez <carlos@facturascripts.com>
 */
class EditWebTeam extends EditSectionController
{

    /**
     * This team.
     *
     * @var WebTeam
     */
    protected $team;

    /**
     * Returns true if contact can edit this webteam.
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

        $team = $this->getMainModel();
        return ($team->idcontacto === $this->contact->idcontacto);
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
     * Returns the team details.
     * 
     * @param bool $reload
     *
     * @return WebTeam
     */
    public function getMainModel($reload = false)
    {
        if (isset($this->team) && !$reload) {
            return $this->team;
        }

        $this->team = new WebTeam();
        $uri = explode('/', $this->uri);
        if ($this->team->loadFromCode('', [new DataBaseWhere('name', end($uri))])) {
            return $this->team;
        }

        $code = $this->request->query->get('code', '');
        $this->team->loadFromCode($code);
        return $this->team;
    }

    /**
     * Returns the status of this contact in this team: in|pendirg|out.
     *
     * @return string
     */
    public function getMemberStatus()
    {
        if (empty($this->contact)) {
            return 'out';
        }

        $member = new WebTeamMember();
        $team = $this->getMainModel();
        $where = [
            new DataBaseWhere('idcontacto', $this->contact->idcontacto),
            new DataBaseWhere('idteam', $team->idteam),
        ];

        if ($member->loadFromCode('', $where)) {
            return $member->accepted ? 'in' : 'pending';
        }

        return 'out';
    }

    /**
     * Code for accept action.
     */
    protected function acceptAction()
    {
        if (!$this->contactCanEdit()) {
            $this->miniLog->alert($this->i18n->trans('not-allowed-modify'));
            $this->response->setStatusCode(Response::HTTP_UNAUTHORIZED);
            return;
        }

        $idRequest = $this->request->get('idrequest', '');
        $member = new WebTeamMember();
        if ('' === $idRequest || !$member->loadFromCode($idRequest)) {
            $this->miniLog->alert($this->i18n->trans('record-save-error'));
            return;
        }

        $member->accepted = true;
        if ($member->save()) {
            $this->miniLog->info($this->i18n->trans('record-updated-correctly'));

            $nick = is_null($this->contact) ? $this->user->nick : $this->contact->alias();
            $teamLog = new WebTeamLog();
            $teamLog->description = 'Accepted as new member by ' . $nick . '.';
            $teamLog->idcontacto = $member->idcontacto;
            $teamLog->idteam = $member->idteam;
            $teamLog->save();
            $this->notifyAccept($member);
        }
    }

    protected function createSectionLogs($name = 'ListWebTeamLog')
    {
        $this->addListSection($name, 'WebTeamLog', 'logs', 'fas fa-file-medical-alt');
        $this->sections[$name]->template = 'Section/TeamLogs.html.twig';
        $this->addSearchOptions($name, ['description']);
        $this->addOrderOption($name, ['time'], 'date', 2);
    }

    protected function createSectionMembers($name, $label, $icon)
    {
        $this->addListSection($name, 'WebTeamMember', $label, $icon);
        $this->sections[$name]->template = 'Section/TeamMembers.html.twig';
        $this->addOrderOption($name, ['creationdate'], 'date', 2);
    }

    protected function createSectionPublications($name = 'ListPublication')
    {
        $this->addListSection($name, 'Publication', 'publications', 'fas fa-newspaper');
        $this->addOrderOption($name, ['creationdate'], 'date', 2);
        $this->addSearchOptions($name, ['title', 'body']);

        /// buttons
        if ($this->contactCanEdit()) {
            $team = $this->getMainModel();
            $button = [
                'action' => 'AddPublication?idteam=' . $team->idteam,
                'color' => 'success',
                'icon' => 'fas fa-plus',
                'label' => 'new',
                'type' => 'link'
            ];
            $this->addButton($name, $button);
        }
    }

    /**
     * Load sections to the view.
     */
    protected function createSections()
    {
        $this->fixedSection();
        $this->addHtmlSection('team', 'team', 'Section/Team');
        $team = $this->getMainModel();
        $this->addNavigationLink($team->url('public-list'), $this->i18n->trans('teams'));

        $this->createSectionPublications();
        $this->createSectionLogs();
        $this->createSectionMembers('ListWebTeamMember', 'members', 'fas fa-users');
        $this->createSectionMembers('ListWebTeamMember-req', 'requests', 'fas fa-address-card');

        /// admin
        if ($this->contactCanEdit()) {
            $this->addEditSection('EditWebTeam', 'WebTeam', 'edit', 'fas fa-edit', 'admin');
            $this->addEditListSection('EditWebTeamMember', 'WebTeamMember', 'members', 'fas fa-users', 'admin');
        }
    }

    /**
     * Runs the controller actions after data read.
     *
     * @param string $action
     */
    protected function execAfterAction(string $action)
    {
        switch ($action) {
            case 'accept-request':
            case 'join':
            case 'leave':
                /// we force save to update number of members and requests
                $this->getMainModel(true)->save();
                break;

            default:
                parent::execAfterAction($action);
        }
    }

    /**
     * Run the actions that alter data before reading it.
     *
     * @param string $action
     *
     * @return bool
     */
    protected function execPreviousAction(string $action)
    {
        switch ($action) {
            case 'accept-request':
                $this->acceptAction();
                break;

            case 'expel':
                $this->expelAction();
                break;

            case 'join':
                $this->joinAction();
                break;

            case 'leave':
                $this->leaveAction();
                break;
        }

        return parent::execPreviousAction($action);
    }

    protected function expelAction()
    {
        if (!$this->contactCanEdit()) {
            $this->miniLog->alert($this->i18n->trans('not-allowed-modify'));
            $this->response->setStatusCode(Response::HTTP_UNAUTHORIZED);
            return;
        }

        $member = new WebTeamMember();
        $code = $this->request->get('idrequest');
        if (!$member->loadFromCode($code)) {
            $this->miniLog->alert($this->i18n->trans('record-save-error'));
            return;
        }

        if ($member->delete()) {
            $this->miniLog->info($this->i18n->trans('record-updated-correctly'));
            $teamLog = new WebTeamLog();
            $teamLog->description = 'Expelled from this team.';
            $teamLog->idcontacto = $member->idcontacto;
            $teamLog->idteam = $member->idteam;
            $teamLog->save();
        }
    }

    /**
     * Code for join action.
     */
    protected function joinAction()
    {
        if (empty($this->contact)) {
            $this->miniLog->warning($this->i18n->trans('login-to-continue'));
            $this->response->setStatusCode(Response::HTTP_UNAUTHORIZED);
            return;
        }

        $team = $this->getMainModel();
        $member = new WebTeamMember();
        $member->idcontacto = $this->contact->idcontacto;
        $member->idteam = $team->idteam;
        $member->observations = $this->request->request->get('observations', '');
        if ($this->user) {
            $member->accepted = true;
        }

        if ($member->save()) {
            $this->miniLog->info($this->i18n->trans('record-updated-correctly'));
            $teamLog = new WebTeamLog();
            $teamLog->idcontacto = $member->idcontacto;
            $teamLog->idteam = $member->idteam;
            $teamLog->description = 'Wants to be member of this team.';
            $teamLog->save();
        } else {
            $this->miniLog->alert($this->i18n->trans('record-save-error'));
        }
    }

    /**
     * Code for leave action.
     */
    protected function leaveAction()
    {
        if (empty($this->contact)) {
            return;
        }

        $member = new WebTeamMember();
        $team = $this->getMainModel();
        $where = [
            new DataBaseWhere('idcontacto', $this->contact->idcontacto),
            new DataBaseWhere('idteam', $team->idteam),
        ];

        if (!$member->loadFromCode('', $where)) {
            $this->miniLog->alert($this->i18n->trans('record-save-error'));
            return;
        }

        if ($member->delete()) {
            $this->miniLog->info($this->i18n->trans('record-updated-correctly'));
            $teamLog = new WebTeamLog();
            $teamLog->description = 'Leaves this team.';
            $teamLog->idcontacto = $member->idcontacto;
            $teamLog->idteam = $member->idteam;
            $teamLog->save();
        }
    }

    /**
     * Load section data procedure
     *
     * @param string $sectionName
     */
    protected function loadData(string $sectionName)
    {
        $team = $this->getMainModel();
        $where = [new DataBaseWhere('idteam', $team->idteam)];
        switch ($sectionName) {
            case 'EditWebTeam':
                $this->sections[$sectionName]->loadData($team->primaryColumnValue());
                break;

            case 'ListPublication':
                $this->sections[$sectionName]->loadData('', $where, ['ordernum' => 'ASC', 'creationdate' => 'DESC']);
                break;

            case 'ListWebTeamLog':
                $this->sections[$sectionName]->loadData('', $where, ['time' => 'DESC']);
                break;

            case 'EditWebTeamMember':
                $this->sections[$sectionName]->loadData('', $where);
                break;

            case 'ListWebTeamMember':
                $where[] = new DataBaseWhere('accepted', true);
                $this->sections[$sectionName]->loadData('', $where);
                break;

            case 'ListWebTeamMember-req':
                $where[] = new DataBaseWhere('accepted', false);
                $this->sections[$sectionName]->loadData('', $where);
                break;

            case 'team':
                $this->loadTeam();
                break;
        }
    }

    /**
     * Load team details.
     */
    protected function loadTeam()
    {
        if ($this->getMainModel(true)->exists()) {
            $this->title = $this->team->name;
            $this->description = $this->team->description();

            $ipAddress = is_null($this->request->getClientIp()) ? '::1' : $this->request->getClientIp();
            $this->team->increaseVisitCount($ipAddress);
            return;
        }

        $this->miniLog->alert($this->i18n->trans('no-data'));
        $this->response->setStatusCode(Response::HTTP_NOT_FOUND);
        $this->webPage->noindex = true;
        $this->setTemplate('Master/Portal404');
    }

    /**
     * Notify to member that was accepted to team.
     *
     * @param WebTeamMember $member
     */
    protected function notifyAccept(WebTeamMember $member)
    {
        $contact = $member->getContact();
        $team = $this->getMainModel();
        $link = AppSettings::get('webportal', 'url', '') . $team->url('public');
        $title = $this->i18n->trans('accepted-to-team', ['%teamName%' => $team->name]);
        $txt = $this->i18n->trans('accepted-to-team-msg', ['%link%' => $link, '%teamName%' => $team->name, '%teamDescription%' => $team->description]);

        $emailTools = new EmailTools();
        $mail = $emailTools->newMail();
        $mail->addAddress($contact->email, $contact->fullName());
        $mail->Subject = $title;
        $mail->msgHTML($txt);
        if ($mail->send()) {
            $this->miniLog->notice($this->i18n->trans('email-sent'));
        }
    }
}
