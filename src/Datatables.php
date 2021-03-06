<?php
namespace Ozdemir\Datatables;

use Ozdemir\Datatables\DB\DatabaseInterface;

class Datatables {

    protected $db;
    protected $data;
    protected $recordstotal;
    protected $recordsfiltered;
    protected $columns;
    protected $edit;
    protected $sql;

    function __construct(DatabaseInterface $db)
    {
        $this->db = $db->connect();
        $this->input = isset($_POST["draw"]) ? $_POST : $_GET;
    }

    public function query($query)
    {
        $this->columns = $this->setcolumns($query);
        $columns = implode(", ", $this->columns);
        $query = rtrim($query, "; ");
        $this->sql = "Select $columns from ($query)t";

        return $this;
    }

    protected function execute()
    {
        $this->recordstotal = $this->db->count($this->sql); // unfiltered data count is here.
        $where = $this->filter();
        $this->recordsfiltered = $this->db->count($this->sql . $where);  // filtered data count is here.

        $this->data = $this->db->query($this->sql . $where . $this->orderby() . $this->limit());

        return $this;
    }

    private function filter()
    {
        $search = '';

        $filterglobal = $this->filterglobal();
        $filterindividual = $this->filterindividual();

        if ( ! $filterindividual && ! $filterglobal)
        {
            return null;
        }

        $search .= $filterglobal;

        if ($filterindividual <> null && $filterglobal <> null)
        {
            $search .= ' AND ';
        }

        $search .= $filterindividual;
        $search = " WHERE " . $search;

        return $search;
    }

    private function filterglobal()
    {
        $searchinput = $this->input('search')['value'];
        $allcolumns = $this->input('columns');

        if ($searchinput == '')
        {
            return null;
        }

        $search = " (";

        foreach ($this->columns as $key => $column)
        {
            if ($allcolumns[ $key ]['searchable'] == 'true')
            {
                $lookfor[] = $column . " LIKE " . $this->db->escape('%' . $searchinput . '%') . "";
            }
        }

        $search .= implode(" OR ", $lookfor) . ")";

        return $search;

    }

    private function filterindividual()
    {
        $allcolumns = $this->input('columns');

        $search = " (";
        $lookfor = [];

        if ( ! $allcolumns)
        {
            return null;
        }

        foreach ($allcolumns as $key)
        {
            if ($key['search']['value'] <> "" and $key['searchable'] == 'true')
            {
                $lookfor[] = $this->column($key['data']) . " LIKE " . $this->db->escape('%' . $key['search']['value'] . '%') . "";
            }
        }

        if (count($lookfor) > 0)
        {
            $search .= implode(" AND ", $lookfor) . ")";

            return $search;
        }

        return null;
    }

    private function setcolumns($query)
    {
        preg_match_all("/SELECT([\s\S]*?)((\s*)FROM(?![\s\S]*\)))([\s\S]*?)/i", $query, $columns);

        $columns = $this->explode(",", $columns[1][0]);

        // gets alias of the table -> 'table.column as col' or 'table.column col' to 'col'
        $regex[] = "/(.*)\s+as\s+(.*)/i";
        $regex[] = "/.+(\([^()]+\))?\s+(.+)/i";
        // wipe unwanted characters => '`" and space
        $regex[] = '/[\s"\'`]+/';
        // if there is no alias, return column name -> table.column to column
        $regex[] = "/([\w\-]*)\.([\w\-]*)/";

        return preg_replace($regex, "$2", $columns);
    }

    private function limit()
    {
        $take = 10;
        $skip = (integer) $this->input('start');

        if ($this->input('length'))
        {
            $take = (integer) $this->input('length');
        }

        if ($take == - 1 || !$this->input('draw'))
        {
            return null;
        }

        return " LIMIT $skip, $take ";
    }

    private function orderby()
    {
        $dtorders = $this->input('order');
        $orders = " ORDER BY ";
        $dir = ['asc' => 'asc', 'desc' => 'desc'];

        if ( ! is_array($dtorders))
        {
            return $orders . $this->columns[0] . " asc";  // default
        }

        foreach ($dtorders as $order)
        {
            $takeorders[] = $this->columns[ $order['column'] ] . " " . $dir[ $order['dir'] ];
        }

        return $orders . implode(",", $takeorders);
    }

    public function generate($json = true)
    {
        $this->execute();
        $formatted_data = [];

        foreach ($this->data as $key => $row)
        {
            // editing columns..
            if (count($this->edit) > 0)
            {
                foreach ($this->edit as $edit_column => $closure)
                {
                    $row[ $edit_column ] = $this->exec_replace($closure, $row);
                }
            }

            $formatted_data[] = $this->isIndexed($row);
        }

        $response['draw'] = $this->input('draw');
        $response['recordsTotal'] = $this->recordstotal;
        $response['recordsFiltered'] = $this->recordsfiltered;
        $response['data'] = $formatted_data;

        return $this->response($response, $json);
    }

    public function edit($column, $closure)
    {
        $this->edit[ $column ] = $closure;

        return $this;
    }

    public function input($input)
    {
        if (isset($this->input[ $input ]))
        {
            return $this->input[ $input ];
        }

        return false;
    }

    private function column($input)
    {
        if (is_numeric($input))
        {
            return $this->columns[ $input ];
        }

        return $input;
    }

    protected function exec_replace($closure, $row_data)
    {
        return $closure($row_data);
    }

    protected function response($data, $json = true)
    {
        if ($json)
        {
            header('Content-type: application/json');
            return json_encode($data);
        }

        return $data;
    }

    protected function isIndexed($row) // if data source uses associative keys or index number
    {
        $column = $this->input('columns');
        if (is_numeric($column[0]['data']))
        {
            return array_values($row);
        }

        return $row;
    }

    private function balanceChars($str, $open, $close)
    {
        $openCount = substr_count($str, $open);
        $closeCount = substr_count($str, $close);
        $retval = $openCount - $closeCount;

        return $retval;
    }

    private function explode($delimiter, $str, $open = '(', $close = ')')
    {
        $retval = array();
        $hold = array();
        $balance = 0;
        $parts = explode($delimiter, $str);
        foreach ($parts as $part)
        {
            $hold[] = $part;
            $balance += $this->balanceChars($part, $open, $close);
            if ($balance < 1)
            {
                $retval[] = implode($delimiter, $hold);
                $hold = array();
                $balance = 0;
            }
        }
        if (count($hold) > 0)
        {
            $retval[] = implode($delimiter, $hold);
        }

        return $retval;
    }
}