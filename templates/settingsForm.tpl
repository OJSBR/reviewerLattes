{**
 * plugins/generic/reviewerLattes/templates/settingsForm.tpl
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Settings of the Lattes field.
 *}
<script type="text/javascript">
	$(function() {ldelim}
		$('#reviewerLattesSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<form class="pkp_form" id="reviewerLattesSettingsForm" method="post" action="{url router=PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}">
	{csrf}

	{include file="controllers/notification/inPlaceNotification.tpl" notificationId="reviewerLattesFormNotification"}

	<p>{translate key="plugins.generic.reviewerLattes.settings.intro"}</p>

	{fbvFormArea id="reviewerLattesScope" title="plugins.generic.reviewerLattes.settings.scope"}
		{fbvFormSection list=true description="plugins.generic.reviewerLattes.settings.scope.description"}
			{foreach from=$requiredScopeOptions key=value item=labelKey}
				{if $requiredScope == $value}{assign var=isChecked value=true}{else}{assign var=isChecked value=false}{/if}
				{fbvElement type="radio" id="requiredScope-$value" name="requiredScope" value=$value checked=$isChecked label=$labelKey}
			{/foreach}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormArea id="reviewerLattesStudents" title="plugins.generic.reviewerLattes.settings.student"}
		{fbvFormSection for="studentEmailPatterns" description="plugins.generic.reviewerLattes.settings.student.description"}
			{fbvElement type="textarea" id="studentEmailPatterns" value=$studentEmailPatterns label="plugins.generic.reviewerLattes.settings.studentEmailPatterns" rows=6}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormButtons}
</form>
