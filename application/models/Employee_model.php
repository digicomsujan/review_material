<?php

class Employee_model extends CI_Model
{
    public function __construct()
    {
        parent::__construct();
    }

    public function get_employee_list($company_id)
    {
        $this->db->where("company", $company_id);
        $query = $this->db->get('employee');
        return $query->result();
    }

    public function check_holiday($date, $company_id)
    {
        $this->db->where("company_id", $company_id);
        $this->db->where("leave_date", $date);
        $query = $this->db->get('holiday');
        return $query->result();
    }
    function check_leave($emp_id, $data, $company_id = '')
    {
        $employee = $this->employee_model->find($emp_id);
        # displayArr($employee);
        $date = date('Y-m-d', strtotime($data));
        if ($company_id == '') {
            $company_id = $this->session->userdata('company_id');
        }
        if ($employee->id == '') return false;

        /*	$sql = "SELECT * FROM leave WHERE company_id=" . $company_id .
				" AND employee_id = " . $employee->id .
				" AND status = 'APPROVED' " .
				" AND date(leave_from) >= '{$date}' AND date(leave_to) <= '{$date}'";*/

        $sql = "SELECT * FROM `leave` where company_id = '{$company_id}'  and employee_id = '{$employee->id}'   and status = 'APPROVED' ";
        // " and '{$date}' between leave_from and leave_to";

        $result = $this->db->query($sql)->row();

        if ($result) {
            return $result;
        }

        return false;
    }

    public function find($employee_id)
    {
        $this->db->where("empid", $employee_id);
        $query = $this->db->get('employee');
        return $query->row();
    }

    public function check_ret_present($emp_id, $mdate)
    {
        $company_id = 15;

        $prev_date = date('Y-m-d', strtotime('-1 day', strtotime($mdate)));
        $next_date = date('Y-m-d', strtotime('+1 day', strtotime($mdate)));
        //echo "<br>" . $mdate . "::" . $prev_date . "=" . $next_date . "<br>";
        $prevarg = explode('-', $prev_date);

        //        if ($this->check_holiday($prev_date)) {
        //            $prev_present = false;
        //        } else {
        //            $prev_present = true;
        //        }
        //        $next_present = false;
        //        #echo $emp_id;

        if ($this->check_holiday($prev_date, $company_id)) {
            $before_prev_date = date("Y-m-d", strtotime('-1 day', strtotime($prev_date)));
            $prev_present = $this->check_awa($emp_id, $before_prev_date);
        } else {
            $prev_present = $this->check_awa($emp_id, $prev_date);
        }

        #displayArr($prev_present);
        //if ($prev_present) {
        $next_present = $this->check_awa($emp_id, $next_date);
        //            if ($next_present == 'A') $next_present = false;
        //            else $next_present = false;
        //}

        // echo $prev_present . '=' . $next_present . "<br>";

        if ($prev_present || $next_present) {
            return true;
        } else return false;
    }

    function check_awa($emp_id, $mdate)
    {
        $checkin = $this->db->where(
            array(
                'companyid' => $this->session->userdata('company_id'),
                'enrollNumber' => $emp_id,
                'date(signinDate)' => $mdate
            )
        )->order_by('signinTime', 'asc')->get('devicelog')->row();
        if ($checkin) return true;
        else return false;
    }
}
