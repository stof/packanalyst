<?php
namespace Mouf\Packanalyst\Controllers;
				
use Mouf\Mvc\Splash\Controllers\Controller;
use Mouf\Html\Template\TemplateInterface;
use Mouf\Html\HtmlElement\HtmlBlock;
use Psr\Log\LoggerInterface;
use \Twig_Environment;
use Mouf\Html\Renderer\Twig\TwigTemplate;
use Mouf\Packanalyst\Widgets\Graph;
use Mouf\Packanalyst\Dao\ItemDao;
use Mouf\Reflection\MoufPhpDocComment;
use Michelf\MarkdownExtra;
use Mouf\Packanalyst\Widgets\Node;
use Mouf\Packanalyst\Dao\PackageDao;
use Mouf\Html\Utils\WebLibraryManager\WebLibrary;

/**
 * TODO: write controller comment
 */
class ClassAnalyzerController extends Controller {

	/**
	 * The logger used by this controller.
	 * @var LoggerInterface
	 */
	private $logger;
	
	/**
	 * The template used by this controller.
	 * @var TemplateInterface
	 */
	private $template;
	
	/**
	 * The main content block of the page.
	 * @var HtmlBlock
	 */
	private $content;
	
	/**
	 * The Twig environment (used to render Twig templates).
	 * @var Twig_Environment
	 */
	private $twig;

	/**
	 * 
	 * @var ItemDao
	 */
	private $itemDao;
	
	/**
	 * 
	 * @var PackageDao
	 */
	private $packageDao;
	
	private $packagesCache = array();

	/**
	 * Controller's constructor.
	 * @param LoggerInterface $logger The logger
	 * @param TemplateInterface $template The template used by this controller
	 * @param HtmlBlock $content The main content block of the page
	 * @param Twig_Environment $twig The Twig environment (used to render Twig templates)
	 * @param ItemDao $itemDao
	 * @param PackageDao $packageDao
	 */
	public function __construct(LoggerInterface $logger, TemplateInterface $template, HtmlBlock $content, Twig_Environment $twig, ItemDao $itemDao, PackageDao $packageDao) {
		$this->logger = $logger;
		$this->template = $template;
		$this->content = $content;
		$this->twig = $twig;
		$this->itemDao = $itemDao;
		$this->packageDao = $packageDao;
	}
	
	/**
	 * @URL class	 
	 * @Get
	 * @param string $q
	 */
	public function index($q) {
		
		// Remove front \
		$q = ltrim($q, '\\');
		
		$graphItems = $this->findItemsInheriting($q);
		
		$rootNodesCollection = $this->itemDao->getItemsByName($q);
		// If there is no root node (for instance if the class is "Exception")
		if ($rootNodesCollection->count() == 0) {
			
			// If this class has never been used, we might want to wonder if the class exists at all.
			if (count($graphItems) == 0) {
				// Let's go on a 404.
				header("HTTP/1.0 404 Not Found");
				$this->content->addHtmlElement(new TwigTemplate($this->twig, 'src/views/classAnalyzer/404.twig', array("class"=>$q)));
				$this->template->toHtml();
				return;
			}
			
			$rootNodes = [[
				"name"=>$q
			]];
		} else {
			$rootNodes = [];
			foreach ($rootNodesCollection as $key=>$item) {
				$rootNodes[$key] = $item;
				$packageName = $item['packageName'];
				if (!isset($this->packagesCache[$packageName])) {
					$this->packagesCache[$packageName] = $this->packageDao->getPackagesByName($packageName)->getNext();
				}
				$rootNodes[$key]['package'] = $this->packagesCache[$packageName];
			}
		}
		$graph = new Graph($rootNodes, $graphItems);
		
		// Let's extract the PHPDoc from the latest version (dev version):
		foreach ($rootNodes as $rootNode) {
			if (isset($rootNode['packageVersion']) && strpos($rootNode['packageVersion'], "-dev") !== false) {
				break;
			}
		}
		if ($rootNode && isset($rootNode['phpDoc'])) {
			$docBlock = new MoufPhpDocComment($rootNode['phpDoc']);
			$md = $docBlock->getComment();
			$description = MarkdownExtra::defaultTransform($md)	;
			
			// Let's purify HTML to avoid any attack:
			$config = \HTMLPurifier_Config::createDefault();
			$purifier = new \HTMLPurifier($config);
			$description = $purifier->purify($description);
			
		} else {
			$description = '';
		}
		if ($rootNode && isset($rootNode['type'])) {
			$type = $rootNode['type'];
		} else {
			$type = "class";
		}
		
		// Let's compute the pointer to the source.
		if (isset($rootNode['packageName'])) {
			// TODO: improve to get the link to the best package
			$package = $this->packageDao->get($rootNode['packageName'], $rootNode['packageVersion']);
			$sourceUrl = null;
			if (isset($package['sourceUrl']) && strpos($package['sourceUrl'], 'https://github.com') === 0) {
				if (strpos($package['sourceUrl'], '.git') === strlen($package['sourceUrl'])-4) {
					if (isset($rootNode['fileName']) && $rootNode['fileName']) {
						$sourceUrl = substr($package['sourceUrl'], 0, strlen($package['sourceUrl'])-4);
						$version = str_replace(['dev-', '.x-dev'], ['', ''], $package['packageVersion']);
						$sourceUrl .= '/blob/'.$version.$rootNode['fileName'];
					}
				}
			}
		} else {
			$sourceUrl = null;
		}
		
		
		// Now, let's find all the classes/interfaces we extend from (recursively...)
		$inheritNodes = $this->getNode($q);
		
		// Let's add the twig file to the template.
		$this->template->setTitle('Packanalyst | '.ucfirst($type).' '.$q);
		$this->template->getWebLibraryManager()->addLibrary(new WebLibrary([ROOT_URL.'src/views/classAnalyzer/classAnalyzer.js']));
		$this->content->addHtmlElement(new TwigTemplate($this->twig, 'src/views/classAnalyzer/index.twig', 
				array(
						"class"=>$q, 
						"graph"=>$graph, 
						"description"=>$description, 
						"type"=>$type, 
						"inheritNodes"=>$inheritNodes,
						"sourceUrl"=>$sourceUrl)));
		$this->template->toHtml();
	}
	
	/**
	 * Finds a list of classes/interfaces inheriting the passed interface.
	 * The items returned will contain a special "package" key pointing to the package array.
	 * @param string $className
	 */
	private function findItemsInheriting($className) {
		$graphItems = $this->itemDao->findItemsInheriting($className);
		
		
		$items = [];
		
		foreach ($graphItems as $item) {
			$packageName = $item['packageName'];
			if (!isset($this->packagesCache[$packageName])) {
				$this->packagesCache[$packageName] = $this->packageDao->getPackagesByName($packageName)->getNext();
			}
			$item['package'] = $this->packagesCache[$packageName];
			$items[] = $item;
		}
		
		return $items;
	}
	
	private $inheritedNodes = array();
	
	private function getNode($className) {
		if (isset($inheritedNodes[$className])) {
			return $inheritedNodes[$className];
		}
		
		$nodes = $this->itemDao->getItemsByName($className);
		if ($nodes->hasNext()) {
			$mainNode = $nodes->getNext();
			$type = isset($mainNode['type'])?$mainNode['type']:null;
		} else {
			$type = null;
		}
		
		$htmlNode = new Node($className, $type);
		
		$inherits = array();
		foreach ($nodes as $node) {
			if (isset($node['packageName'])) {
				$packageName = $node['packageName'];
				if (!isset($this->packagesCache[$packageName])) {
					$this->packagesCache[$packageName] = $this->packageDao->getPackagesByName($packageName)->getNext();
				}
				$htmlNode->registerPackage($node['packageName'], $node['packageVersion'], isset($this->packagesCache[$packageName]['downloads'])?$this->packagesCache[$packageName]['downloads']:null, isset($this->packagesCache[$packageName]['favers'])?$this->packagesCache[$packageName]['favers']:null);
			}
			if (isset($node['inherits'])) {
				$inherits = array_merge($inherits, $node['inherits']);
			}
		}
		$inherits = array_keys(array_flip($inherits));
		
		foreach ($inherits as $inherit) {
			$htmlNode->addChild($this->getNode($inherit));
		}
		
		$inheritedNodes[$className] = $htmlNode;
		return $htmlNode;
	}
	
}
