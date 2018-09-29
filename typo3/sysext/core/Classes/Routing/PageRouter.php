<?php
declare(strict_types = 1);

namespace TYPO3\CMS\Core\Routing;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use Doctrine\DBAL\Connection;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Symfony\Component\Routing\Exception\MissingMandatoryParametersException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\LanguageAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendWorkspaceRestriction;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Routing\Aspect\AspectFactory;
use TYPO3\CMS\Core\Routing\Aspect\MappableProcessor;
use TYPO3\CMS\Core\Routing\Aspect\StaticMappableAspectInterface;
use TYPO3\CMS\Core\Routing\Enhancer\DecoratingEnhancerInterface;
use TYPO3\CMS\Core\Routing\Enhancer\EnhancerFactory;
use TYPO3\CMS\Core\Routing\Enhancer\EnhancerInterface;
use TYPO3\CMS\Core\Routing\Enhancer\ResultingInterface;
use TYPO3\CMS\Core\Routing\Enhancer\RoutingEnhancerInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Page\CacheHashCalculator;
use TYPO3\CMS\Frontend\Page\PageRepository;

/**
 * Page Router - responsible for a page based on a request, by looking up the slug of the page path.
 * Is also used for generating URLs for pages.
 *
 * Resolving is done via the "Route Candidate" pattern.
 *
 * Example:
 * - /about-us/team/management/
 *
 * will look for all pages that have
 * - /about-us
 * - /about-us/
 * - /about-us/team
 * - /about-us/team/
 * - /about-us/team/management
 * - /about-us/team/management/
 *
 * And create route candidates for that.
 *
 * Please note: PageRouter does not restrict the HTTP method or is bound to any domain constraints,
 * as the SiteMatcher has done that already.
 *
 * The concept of the PageRouter is to *resolve*, and to *generate* URIs. On top, it is a facade to hide the
 * dependency to symfony and to not expose its logic.
 */
class PageRouter implements RouterInterface
{
    /**
     * @var Site
     */
    protected $site;

    /**
     * @var EnhancerFactory
     */
    protected $enhancerFactory;

    /**
     * @var AspectFactory
     */
    protected $aspectFactory;

    /**
     * @var CacheHashCalculator
     */
    protected $cacheHashCalculator;

    /**
     * A page router is always bound to a specific site.
     * @param Site $site
     */
    public function __construct(Site $site)
    {
        $this->site = $site;
        $this->enhancerFactory = GeneralUtility::makeInstance(EnhancerFactory::class);
        $this->aspectFactory = GeneralUtility::makeInstance(AspectFactory::class);
        $this->cacheHashCalculator = GeneralUtility::makeInstance(CacheHashCalculator::class);
    }

    /**
     * Finds a RouteResult based on the given request.
     *
     * @param ServerRequestInterface $request
     * @param RouteResultInterface|SiteRouteResult|null $previousResult
     * @return SiteRouteResult
     */
    public function matchRequest(ServerRequestInterface $request, RouteResultInterface $previousResult = null): ?RouteResultInterface
    {
        $urlPath = $previousResult->getTail();
        $slugCandidates = $this->getCandidateSlugsFromRoutePath($urlPath ?: '/');
        $language = $previousResult->getLanguage();
        $pageCandidates = $this->getPagesFromDatabaseForCandidates($slugCandidates, $language->getLanguageId());
        // Stop if there are no candidates
        if (empty($pageCandidates)) {
            return null;
        }

        $decoratedParameters = [];
        $fullCollection = new RouteCollection();
        foreach ($pageCandidates ?? [] as $page) {
            $pageIdForDefaultLanguage = (int)($page['l10n_parent'] ?: $page['uid']);
            $pagePath = $page['slug'];
            $pageCollection = new RouteCollection();
            $defaultRouteForPage = new Route(
                $pagePath,
                [],
                [],
                ['utf8' => true, '_page' => $page]
            );
            $pageCollection->add('default', $defaultRouteForPage);
            $enhancers = $this->getEnhancersForPage($pageIdForDefaultLanguage, $language);
            foreach ($enhancers as $enhancer) {
                if ($enhancer instanceof DecoratingEnhancerInterface) {
                    $enhancer->decorateForMatching($pageCollection, $decoratedParameters, $urlPath);
                }
            }
            foreach ($enhancers as $enhancer) {
                if ($enhancer instanceof RoutingEnhancerInterface) {
                    $enhancer->enhanceForMatching($pageCollection);
                }
            }

            $pageCollection->addNamePrefix('page_' . $page['uid'] . '_');
            $fullCollection->addCollection($pageCollection);
        }

        $matcher = new PageUriMatcher($fullCollection);
        try {
            $result = $matcher->match('/' . trim($urlPath, '/'));
            /** @var Route $matchedRoute */
            $matchedRoute = $fullCollection->get($result['_route']);
            $matchedRoute->setOption('_decoratedParameters', $decoratedParameters);
            return $this->buildPageArguments($matchedRoute, $result, $request->getQueryParams());
        } catch (ResourceNotFoundException $e) {
            // return nothing
        }
        return null;
    }

    /**
     * API for generating a page where the $route parameter is typically an array (page record) or the page ID
     *
     * @param array|string $route
     * @param array $parameters an array of query parameters which can be built into the URI path, also consider the special handling of "_language"
     * @param string $fragment additional #my-fragment part
     * @param string $type see the RouterInterface for possible types
     * @return UriInterface
     */
    public function generateUri($route, array $parameters = [], string $fragment = '', string $type = ''): UriInterface
    {
        // Resolve language
        $language = null;
        $languageOption = $parameters['_language'] ?? null;
        unset($parameters['_language']);
        if ($languageOption instanceof SiteLanguage) {
            $language = $languageOption;
        } elseif ($languageOption !== null) {
            $language = $this->site->getLanguageById((int)$languageOption);
        }
        if ($language === null) {
            $language = $this->site->getDefaultLanguage();
        }

        $pageId = 0;
        if (is_array($route)) {
            $pageId = (int)$route['uid'];
        } elseif (is_scalar($route)) {
            $pageId = (int)$route;
        }

        $context = clone GeneralUtility::makeInstance(Context::class);
        $context->setAspect('language', new LanguageAspect($language->getLanguageId()));
        $pageRepository = GeneralUtility::makeInstance(PageRepository::class, $context);
        $page = $pageRepository->getPage($pageId, true);
        $pagePath = ltrim($page['slug'] ?? '', '/');
        $originalParameters = $parameters;
        $collection = new RouteCollection();
        $defaultRouteForPage = new Route(
            '/' . $pagePath,
            [],
            [],
            ['utf8' => true, '_page' => $page]
        );
        $collection->add('default', $defaultRouteForPage);

        // cHash is never considered because cHash is built by this very method.
        unset($originalParameters['cHash']);
        $enhancers = $this->getEnhancersForPage($pageId, $language);
        foreach ($enhancers as $enhancer) {
            if ($enhancer instanceof RoutingEnhancerInterface) {
                $enhancer->enhanceForGeneration($collection, $originalParameters);
            }
        }
        foreach ($enhancers as $enhancer) {
            if ($enhancer instanceof DecoratingEnhancerInterface) {
                $enhancer->decorateForGeneration($collection, $originalParameters);
            }
        }

        $scheme = $language->getBase()->getScheme();
        $mappableProcessor = new MappableProcessor();
        $context = new RequestContext(
            // page segment (slug & enhanced part) is supposed to start with '/'
            rtrim($language->getBase()->getPath(), '/'),
            'GET',
            $language->getBase()->getHost(),
            $scheme ?: 'http',
            $scheme === 'http' ? $language->getBase()->getPort() ?? 80 : 80,
            $scheme === 'https' ? $language->getBase()->getPort() ?? 443 : 443
        );
        $generator = new UrlGenerator($collection, $context);
        $allRoutes = $collection->all();
        $allRoutes = array_reverse($allRoutes, true);
        $matchedRoute = null;
        $pageRouteResult = null;
        $uri = null;
        // map our reference type to symfony's custom paths
        $referenceType = $type === static::ABSOLUTE_PATH ? UrlGenerator::ABSOLUTE_PATH : UrlGenerator::ABSOLUTE_URL;
        /**
         * @var string $routeName
         * @var Route $route
         */
        foreach ($allRoutes as $routeName => $route) {
            try {
                $parameters = $originalParameters;
                if ($route->hasOption('deflatedParameters')) {
                    $parameters = $route->getOption('deflatedParameters');
                }
                $mappableProcessor->generate($route, $parameters);
                // ABSOLUTE_URL is used as default fallback
                $urlAsString = $generator->generate($routeName, $parameters, $referenceType);
                $uri = new Uri($urlAsString);
                /** @var Route $matchedRoute */
                $matchedRoute = $collection->get($routeName);
                parse_str($uri->getQuery() ?? '', $remainingQueryParameters);
                $pageRouteResult = $this->buildPageArguments($route, $parameters, $remainingQueryParameters);
                break;
            } catch (MissingMandatoryParametersException $e) {
                // no match
            }
        }

        if ($pageRouteResult && $pageRouteResult->areDirty()) {
            // for generating URLs this should(!) never happen
            // if it does happen, generator logic has flaws
            throw new \OverflowException('Route arguments are dirty', 1537613247);
        }

        if ($matchedRoute && $pageRouteResult && $uri instanceof UriInterface
            && !empty($pageRouteResult->getDynamicArguments())
        ) {
            $cacheHash = $this->generateCacheHash($pageId, $pageRouteResult);

            if (!empty($cacheHash)) {
                $queryArguments = $pageRouteResult->getQueryArguments();
                $queryArguments['cHash'] = $cacheHash;
                $uri = $uri->withQuery(http_build_query($queryArguments, '', '&', PHP_QUERY_RFC3986));
            }
        }
        if ($fragment) {
            $uri = $uri->withFragment($fragment);
        }
        // @todo Throw exception in case $uri is null
        return $uri;
    }

    /**
     * Check for records in the database which matches one of the slug candidates.
     *
     * @param array $slugCandidates
     * @param int $languageId
     * @return array
     */
    protected function getPagesFromDatabaseForCandidates(array $slugCandidates, int $languageId): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('pages');
        $queryBuilder
            ->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class))
            ->add(GeneralUtility::makeInstance(FrontendWorkspaceRestriction::class));

        $statement = $queryBuilder
            ->select('uid', 'l10n_parent', 'pid', 'slug')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq(
                    'sys_language_uid',
                    $queryBuilder->createNamedParameter($languageId, \PDO::PARAM_INT)
                ),
                $queryBuilder->expr()->in(
                    'slug',
                    $queryBuilder->createNamedParameter(
                        $slugCandidates,
                        Connection::PARAM_STR_ARRAY
                    )
                )
            )
            // Exact match will be first, that's important
            ->orderBy('slug', 'desc')
            ->execute();

        $pages = [];
        $siteMatcher = GeneralUtility::makeInstance(SiteMatcher::class);
        while ($row = $statement->fetch()) {
            $pageIdInDefaultLanguage = (int)($languageId > 0 ? $row['l10n_parent'] : $row['uid']);
            if ($siteMatcher->matchByPageId($pageIdInDefaultLanguage)->getRootPageId() === $this->site->getRootPageId()) {
                $pages[] = $row;
            }
        }
        return $pages;
    }

    /**
     * Fetch possible enhancers + aspects based on the current page configuration and the site configuration put
     * into "routeEnhancers"
     *
     * @param int $pageId
     * @param SiteLanguage $language
     * @return EnhancerInterface[]
     */
    protected function getEnhancersForPage(int $pageId, SiteLanguage $language): array
    {
        $enhancers = [];
        foreach ($this->site->getConfiguration()['routeEnhancers'] ?? [] as $enhancerConfiguration) {
            // Check if there is a restriction to page Ids.
            if (is_array($enhancerConfiguration['limitToPages'] ?? null) && !in_array($pageId, $enhancerConfiguration['limitToPages'])) {
                continue;
            }
            $enhancerType = $enhancerConfiguration['type'] ?? '';
            /** @var EnhancerInterface $enhancer */
            $enhancer = $this->enhancerFactory->create($enhancerType, $enhancerConfiguration);
            if (!empty($enhancerConfiguration['aspects'] ?? null)) {
                $aspects = $this->aspectFactory->createAspects(
                    $enhancerConfiguration['aspects'],
                    $language
                );
                $enhancer->setAspects($aspects);
            }
            $enhancers[] = $enhancer;
        }
        return $enhancers;
    }

    /**
     * Returns possible URL parts for a string like /home/about-us/offices/ or /home/about-us/offices.json
     * to return.
     *
     * /home/about-us/offices/
     * /home/about-us/offices.json
     * /home/about-us/offices
     * /home/about-us/
     * /home/about-us
     * /home/
     * /home
     *
     * @param string $routePath
     * @return array
     */
    protected function getCandidateSlugsFromRoutePath(string $routePath): array
    {
        $candidatePathParts = [];
        $pathParts = GeneralUtility::trimExplode('/', $routePath, true);
        if (empty($pathParts)) {
            return ['/'];
        }
        // Check if the last part contains a ".", then split it
        // @todo fix me based on enhancer configuration
        $lastPart = array_pop($pathParts);
        if (strpos($lastPart, '.') !== false) {
            $pathParts = array_merge($pathParts, explode('.', $lastPart));
        } else {
            $pathParts[] = $lastPart;
        }
        while (!empty($pathParts)) {
            $prefix = '/' . implode('/', $pathParts);
            $candidatePathParts[] = $prefix . '/';
            $candidatePathParts[] = $prefix;
            array_pop($pathParts);
        }
        return $candidatePathParts;
    }

    /**
     * @param int $pageId
     * @param PageArguments $arguments
     * @return string
     */
    protected function generateCacheHash(int $pageId, PageArguments $arguments): string
    {
        return $this->cacheHashCalculator->calculateCacheHash(
            $this->getCacheHashParameters($pageId, $arguments)
        );
    }

    /**
     * @param int $pageId
     * @param PageArguments $arguments
     * @return array
     */
    protected function getCacheHashParameters(int $pageId, PageArguments $arguments): array
    {
        $hashParameters = $arguments->getDynamicArguments();
        $hashParameters['id'] = $pageId;
        $uri = http_build_query($hashParameters, '', '&', PHP_QUERY_RFC3986);
        return $this->cacheHashCalculator->getRelevantParameters($uri);
    }

    /**
     * Builds route arguments. The important part here is to distinguish between
     * static and dynamic arguments. Per default all arguments are dynamic until
     * aspects can be used to really consider them as static (= 1:1 mapping between
     * route value and resulting arguments).
     *
     * Besides that, internal arguments (_route, _controller, _custom, ..) have
     * to be separated since those values are not meant to be used for later
     * processing. Not separating those values might result in invalid cHash.
     *
     * This method is used during resolving and generation of URLs.
     *
     * @param Route $route
     * @param array $results
     * @param array $remainingQueryParameters
     * @return PageArguments
     */
    protected function buildPageArguments(Route $route, array $results, array $remainingQueryParameters = []): PageArguments
    {
        // only use parameters that actually have been processed
        // (thus stripping internals like _route, _controller, ...)
        $routeArguments = $this->filterProcessedParameters($route, $results);
        // assert amount of "static" mappers is not too "dynamic"
        $this->assertMaximumStaticMappableAmount($route, array_keys($routeArguments));
        // delegate result handling to enhancer
        $enhancer = $route->getEnhancer();
        if ($enhancer instanceof ResultingInterface) {
            // forward complete(!) results, not just filtered parameters
            return $enhancer->buildResult($route, $results, $remainingQueryParameters);
        }
        $page = $route->getOption('_page');
        $pageId = (int)($page['l10n_parent'] > 0 ? $page['l10n_parent'] : $page['uid']);
        $type = $this->resolveType($route, $remainingQueryParameters);
        return new PageArguments($pageId, $type, $routeArguments, [], $remainingQueryParameters);
    }

    /**
     * Retrieves type from processed route and modifies remaining query parameters.
     *
     * @param Route $route
     * @param array $remainingQueryParameters reference to remaining query parameters
     * @return string
     */
    protected function resolveType(Route $route, array &$remainingQueryParameters): string
    {
        $decoratedParameters = $route->getOption('_decoratedParameters');
        if (!isset($decoratedParameters['type'])) {
            return '0';
        }
        $type = (string)$decoratedParameters['type'];
        unset($decoratedParameters['type']);
        $remainingQueryParameters = array_replace_recursive(
            $remainingQueryParameters,
            $decoratedParameters
        );
        return $type;
    }

    /**
     * Asserts that possible amount of items in all static and countable mappers
     * (such as StaticRangeMapper) is limited to 10000 in order to avoid
     * brute-force scenarios and the risk of cache-flooding.
     *
     * @param Route $route
     * @param array $variableNames
     * @throws \OverflowException
     */
    protected function assertMaximumStaticMappableAmount(Route $route, array $variableNames = [])
    {
        $mappers = $route->filterAspects(
            [StaticMappableAspectInterface::class, \Countable::class],
            $variableNames
        );
        if (empty($mappers)) {
            return;
        }

        $multipliers = array_map('count', $mappers);
        $product = array_product($multipliers);
        if ($product > 10000) {
            throw new \OverflowException(
                'Possible range of all mappers is larger than 10000 items',
                1537696772
            );
        }
    }

    /**
     * Determine parameters that have been processed.
     *
     * @param Route $route
     * @param array $results
     * @return array
     */
    protected function filterProcessedParameters(Route $route, $results): array
    {
        return array_intersect_key(
            $results,
            array_flip($route->compile()->getPathVariables())
        );
    }
}
