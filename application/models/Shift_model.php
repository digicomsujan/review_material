<?php

class Shift_model extends CI_Model
{
    public function __construct()
    {
        parent::__construct();
    }

    public function find($shift_id)
    {
        $this->db->where("shift_id", $shift_id);
        $query = $this->db->get('shift');
        return $query->row();
    }

    public function get_employee_shift($roster_date, $empid = '')
    {
        $this->load->model("employee_model");

        $emp = $this->employee_model->find($empid);


        $shift_roster = $this->db->where(
            array(
                'employee_id' => $empid,
                'date(roster_date)' => date('Y-m-d', strtotime($roster_date))
            )
        )->get('shift_roster')->row();


        if (!empty($shift_roster)) {
            return $shift_roster->roster_shift_id;
        } else {
            return $emp->shift;
        }
    }
}
