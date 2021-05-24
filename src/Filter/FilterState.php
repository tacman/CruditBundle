<?php

namespace Lle\CruditBundle\Filter;

use Symfony\Component\HttpFoundation\Session\SessionInterface;

class FilterState
{
    /** @var iterable */
    private $filtersets;
    private SessionInterface $session;
    /**
     * @var null
     */
    private $filterdata;

    public function __construct(iterable $filtersets, SessionInterface $session)
    {
        $this->filtersets = $filtersets;
        $this->session = $session;
        $this->filterdata = null;
    }

    public function isFilterLink($request)
    {
        foreach ($request->query->all() as $k => $val) {
            if (strrpos($k, 'filter_') === 0) {
                return true;
            }
        }
        return false;
    }

    public function handleRequest($request)
    {
        $filterdata = $this->session->get('crudit_filters');
        foreach ($this->filtersets as $filterset) {
            $filterId = $filterset->getId();
            if ($request->query->get($filterId.'_reset')) {
                $filterdata[$filterId] = [];
            } else {

                foreach ($filterset->getFilters() as $filterType) {
                    $key = "filter_" . $filterId . '_' . $filterType->getId();

                    $data = $request->query->get($key . '_value');

                    if ($data) {
                        $filterdata[$filterId][$filterType->getId()]['value'] = $data;
                    }
                    $op = $request->query->get($key . '_op');
                    if ($op) {
                        $filterdata[$filterId][$filterType->getId()]['op'] = $op;
                    }
                }
            }
        }
        $this->filterdata = $filterdata;
        $this->session->set('crudit_filters', $filterdata);
    }

    public function getFilters($crudKey)
    {
        return $this->filters[$crudKey] ?? [];
    }

    public function getData($set_id, $filter_id) {
        $this->loadData();
        return $this->filterdata[$set_id][$filter_id] ?? null;
    }

    protected function loadData() {
        if (!$this->filterdata) {
            $this->filterdata = $this->session->get('crudit_filters');
        }
    }
}