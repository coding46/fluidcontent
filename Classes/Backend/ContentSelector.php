<?php
namespace FluidTYPO3\Fluidcontent\Backend;

/*
 * This file is part of the FluidTYPO3/Fluidcontent project under GPLv2 or later.
 *
 * For the full copyright and license information, please read the
 * LICENSE.md file that was distributed with this source code.
 */

use FluidTYPO3\Fluidcontent\Service\ConfigurationService;
use FluidTYPO3\Flux\Service\FluxService;
use FluidTYPO3\Flux\Utility\MiscellaneousUtility;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use FluidTYPO3\Flux\Form;

/**
 * Class that renders a selection field for Fluid FCE template selection
 */
class ContentSelector {

	/**
	 * @var array
	 */
	protected $templates = array(
		'select' => '<div class="form-control-wrap"><div class="input-group">
			<div class="input-group-addon input-group-icon t3js-formengine-select-prepend"><img src="%s" alt="" /></div>
			<select name="%s" class="form-control form-control-adapt"
				onchange="if (confirm(TBE_EDITOR.labels.onChangeAlert)
					&& TBE_EDITOR.checkSubmit(-1)){ TBE_EDITOR.submitForm() };">
				%s
			</select>
			</div>
			</div>',
		'option' => '<option data-icon="%s" value="%s"%s>%s</option>',
		'optionEmpty' => '<option value="">%s</option>',
		'optgroup' => '<optgroup label="%s">%s</optgroup>'
	);

	/**
	 * Render a Flexible Content Element type selection field
	 *
	 * @param array $parameters
	 * @param mixed $parentObject
	 * @return string
	 */
	public function renderField(array &$parameters, &$parentObject) {
		list($whiteList, $blackList) = $this->resolveWhiteAndBlackList($parameters);

		$configurationService = $this->getConfigurationService();
		$setup = $configurationService->getContentElementFormInstances();

		if (count($whiteList)) {
			$this->applyWhitelist($setup, $whiteList);
		} else if (count($blackList)) {
			$this->applyBlacklist($setup, $blackList);
		}

		$name = $parameters['itemFormElName'];
		$value = $parameters['itemFormElValue'];
		$selectedIcon = $this->getSelectedIcon($setup, $value);
		if (NULL === $selectedIcon) {
			$selectedIcon =	$configurationService->getDefaultIcon();
		}
		$options = $this->renderEmptyOption();
		foreach ($setup as $groupLabel => $configuration) {
			$options .= $this->renderOptionGroup($configuration, $groupLabel, $value);
		}
		return $this->wrapSelector($options, $name, $selectedIcon);
	}

	/**
	 * @param array $setup
	 * @param mixed $value
	 * @return NULL|string
	 */
	protected function getSelectedIcon(array $setup, $value) {
		foreach ($setup as $configuration) {
			/** @var Form $form */
			foreach ($configuration as $form) {
				$optionValue = $form->getOption('contentElementId');
				if ($optionValue === $value) {
					return MiscellaneousUtility::getIconForTemplate($form);
				}
			}
		}
		return NULL;
	}

	/**
	 * @param string $selector
	 * @param string $name
	 * @param string $selectedIcon
	 * @return string
	 */
	protected function wrapSelector($selector, $name, $selectedIcon) {
		return sprintf($this->templates['select'], $selectedIcon, htmlspecialchars($name), $selector);
	}

	/**
	 * @param array $configuration
	 * @param string $groupLabel
	 * @param mixed $value
	 * @return string
	 */
	protected function renderOptionGroup(array $configuration, $groupLabel, $value) {
		$optionGroup = '';
		foreach ($configuration as $form) {
			/** @var Form $form */
			$optionValue = $form->getOption('contentElementId');
			$selected = ($optionValue === $value ? ' selected="selected"' : '');
			$label = $form->getLabel();
			$icon = MiscellaneousUtility::getIconForTemplate($form);
			$label = (0 === strpos($label, 'LLL:') ? $GLOBALS['LANG']->sL($label) : $label);
			$valueString = htmlspecialchars($optionValue);
			$labelString = htmlspecialchars($label);
			$optionGroup .= sprintf($this->templates['option'], $icon, $valueString, $selected, $labelString) . LF;
		}
		return sprintf($this->templates['optgroup'], htmlspecialchars($groupLabel), $optionGroup);
	}

	/**
	 * @return string
	 */
	protected function renderEmptyOption() {
		return sprintf(
			$this->templates['optionEmpty'],
			$GLOBALS['LANG']->sL('LLL:EXT:fluidcontent/Resources/Private/Language/locallang.xml:tt_content.tx_fed_fcefile', TRUE)
		);
	}

	/**
	 * @return ConfigurationService
	 */
	protected function getConfigurationService() {
		/** @var ConfigurationService $configurationService */
		$configurationService = GeneralUtility::makeInstance('TYPO3\CMS\Extbase\Object\ObjectManager')
			->get('FluidTYPO3\Fluidcontent\Service\ConfigurationService');
		return $configurationService;
	}

	/**
	 * @return FluxService
	 */
	protected function getFluxService() {
		/** @var FluxService $fluxService */
		$fluxService = GeneralUtility::makeInstance('TYPO3\CMS\Extbase\Object\ObjectManager')
			->get('FluidTYPO3\Flux\Service\FluxService');
		return $fluxService;
	}

	/**
	 * @param array $parameters
	 * @return array
	 */
	protected function resolveWhiteAndBlackList($parameters) {
		$whiteList = array();
		$blackList = array();

		if (!isset($parameters['row']) || !isset($parameters['row']['tx_flux_parent'])) {
			return array($whiteList, $blackList);
		}

		$fluxService = $this->getFluxService();

		$containerUid = $parameters['row']['tx_flux_parent'];
		$containerElement = BackendUtility::getRecord('tt_content', $containerUid);
		$containerConfigurationProvider = $fluxService->resolvePrimaryConfigurationProvider('tt_content', 'pi_flexform', $containerElement);

		if (!$containerConfigurationProvider) {
			return array($whiteList, $blackList);
		}

		$context = $containerConfigurationProvider->getViewContext($containerElement);

		if ($grid = $fluxService->getGridFromTemplateFile($context)) {
			$gridRows = $grid->getRows();

			foreach ($gridRows as $gridRow) {
				$gridColumns = $gridRow->getColumns();
				foreach ($gridColumns as $gridColumn) {
					/** @var \FluidTYPO3\Flux\Form\Container\Column $gridColumn */
					$allowedElements = preg_replace(array('/\./', '/:/'), '_', $gridColumn->getVariable('Fluidcontent.allowedContentTypes'));
					$deniedElements = preg_replace(array('/\./', '/:/'), '_', $gridColumn->getVariable('Fluidcontent.deniedContentTypes'));
					if ('' !== $allowedElements) {
						$whiteList = array_merge($whiteList, explode(',', $allowedElements));
					}
					if ('' !== $deniedElements) {
						$blackList = array_merge($blackList, explode(',', $deniedElements));
					}
				}
			}
		}

		return array($whiteList, $blackList);
	}

	/**
	 * @param array $contentElementList
	 * @param array $whiteList
	 */
	protected function applyWhitelist(&$contentElementList, $whiteList) {
		foreach ($contentElementList as $extensionKey => $contentElements) {
			foreach ($contentElements as $elementKey => $elementDefinition) {
				if (!in_array($elementKey, $whiteList)) {
					unset($contentElementList[$extensionKey][$elementKey]);
				}
			}
		}
	}

	/**
	 * @param array $contentElementList
	 * @param array $blackList
	 */
	protected function applyBlacklist(&$contentElementList, $blackList) {
		foreach ($contentElementList as $extensionKey => $contentElements) {
			foreach ($contentElements as $elementKey => $elementDefinition) {
				if (in_array($elementKey, $blackList)) {
					unset($contentElementList[$extensionKey][$elementKey]);
				}
			}
		}
	}
}
