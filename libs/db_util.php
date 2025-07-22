<?php
class StmtResultArray {
    private $rows = [];
    private $index = 0;
    public function __construct(array $rows) {
        $this->rows = $rows;
    }
    public function fetch_assoc() {
        if ($this->index < count($this->rows)) {
            return $this->rows[$this->index++];
        }
        return null;
    }
    public function fetch_all($resulttype = MYSQLI_ASSOC) {
        return $this->rows;
    }
    public function __get($name) {
        if ($name === 'num_rows') {
            return count($this->rows);
        }
        return null;
    }
}
function stmt_get_assoc($stmt) {
    if (method_exists($stmt, 'get_result')) {
        $res = @$stmt->get_result();
        if ($res !== false) {
            return $res;
        }
    }
    if (method_exists($stmt, 'store_result')) {
        if (!$stmt->store_result()) {
            die('Error storing result: ' . $stmt->error);
        }
        $meta = $stmt->result_metadata();
        if (!$meta) {
            die('Error fetching result metadata: ' . $stmt->error);
        }
        $row = [];
        $params = [];
        while ($field = $meta->fetch_field()) {
            $params[] = &$row[$field->name];
        }
        if (!empty($params)) {
            call_user_func_array([$stmt, 'bind_result'], $params);
        }
        $rows = [];
        while ($stmt->fetch()) {
            $rows[] = array_map(fn($v) => $v, $row);
        }
        return new StmtResultArray($rows);
    }
    die('Database error: unable to fetch statement results.');
}
?>
