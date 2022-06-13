<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Attendance extends CI_Controller
{

    public function __construct()
    {
        parent::__construct();

        $this->load->model('employee_model');
        $this->load->model('shift_model');
    }

    public function index()
    {
        $user_id = 15;
        $company_id = 101;
        $late_deduction = "yes";

        try {

            $start = '2021-12-01';
            $end = '2021-12-31';

            $this->_createOrUpdateAttendanceSync($company_id, $user_id, $end);

            $employees = $this->_getEmployee($company_id);

            $period = $this->_getDateInterval($start, $end);


            foreach ($employees as $employee) {
                $employee_id = $employee->id;
                $empid = $employee->empid;
                foreach ($period as $dt) {
                    $check_in = "";
                    $check_out = "";


                    $current_date = $dt->format("Y-m-d");
                    $shift = $this->_getEmployeeShift($employee_id, $current_date);
                    $device_log = $this->_getDeviceLog($employee_id, $current_date);
                    $holiday = $this->_checkHoliday($current_date);
                    $leave = $this->_checkLeave($empid, $current_date);
                    $half_day_hour = ($shift->daily_attendance == '') ? 5 : $shift->daily_attendance;

                    $shift_type = $shift->type;
                    $shift_check_in = strtotime($shift->checkin);
                    $shift_check_out = strtotime($shift->checkout);

                    $shift_weekend_list = @$shift->weekend;
                    $day_of_week = date('w', strtotime($current_date));
                    $weekend_arr = json_decode($shift_weekend_list, true);
                    if (is_array($weekend_arr)) {
                        $weekend = $weekend_arr['weekend'];
                    } else {
                        $weekend = array();
                    }

                    if (empty($shift)) {
                        // employee has day off in the roster;
                        $remark = "DO";
                        $class = "dayoff";
                        $check_in = $current_date;
                        $present_days = '0';
                    } else {
                        if ($shift->single_punch == "YES") {
                            if (count($device_log) > 0) {
                                if ($device_log[0]->branch_id != '') {
                                    $checkin_branch = $device_log[0]->branch_id;
                                }
                                if (count($device_log) <= 1) {
                                    $checkout = '';
                                } else {
                                    $device_log_count = count($device_log);
                                    $count = $device_log_count - 1;

                                    $checkout = strtotime($device_log[$count]->signinDate . ' ' .
                                        $device_log[$count]->signinTime);

                                    if ($device_log[$count]->branch_id != '') {
                                        $checkout_branch = $device_log[$count]->branch_id;
                                    }
                                }
                                $remark = "P";
                                $class = "present";
                                $check_in = strtotime($device_log[0]->signinDate . ' ' . $device_log[0]->signinTime);
                                $present_days = '1';
                            } else {
                                $remark = "A1";
                                $class = "absent";
                                $check_in = $current_date;
                                $present_days = '0';
                            }
                        } else {
                            if (empty($device_log)) {
                                $empty_log_data = $this->_checkForEmptyLog($employee_id, $current_date, $shift);
                                $remark = $empty_log_data['remark'];
                                $class = $empty_log_data['class'];
                                $check_in = $empty_log_data['checkin'];
                                $present_days = $empty_log_data['present_days'];
                            } else {
                                $extra_in = date("H:i", strtotime(
                                    '+' . $shift->late_arrival . " minutes",
                                    strtotime($shift->checkin)
                                ));
                                $extra_out = date("H:i", strtotime(
                                    '-' . $shift->early_departure . " minutes",
                                    strtotime($shift->checkout)
                                ));

                                $shift_check_in = strtotime($current_date . " " . $extra_in);
                                $shift_check_out = strtotime($current_date . " " . $extra_out);

                                if ($shift->enable_break == 'YES') {
                                    if (count($device_log) < 2) {
                                        // check if processing date is now (current day)
                                        if ($current_date == date("Y-m-d")) {
                                            $check_in = date("H:i", strtotime(
                                                '+' . $shift->late_arrival . 'minutes',
                                                strtotime($shift->checkin)
                                            ));
                                            if (strtotime($current_date . ' ' . $device_log[0]->signinTime) >= strtotime($current_date . ' ' . $check_in)) {
                                                $remark = 'P';
                                                $class = 'present';
                                                $check_in = strtotime($current_date . ' ' . $device_log[0]->signinTime);

                                                if ($device_log[0]->branch_id != '') {
                                                    $check_in_branch = $device_log[0]->branch_id;
                                                }
                                            }
                                        } else {
                                            $remark = 'L';
                                            $class = 'late';
                                            $check_in = strtotime($current_date . ' ' . $device_log[0]->signinTime);

                                            if ($leave) {
                                                $leave_type = $this->_getLeaveType($leave);
                                                $remark = $leave_type['remark'];
                                                $class = $leave_type['class'];
                                            }
                                        }
                                    } else if (count($device_log) == 2) {
                                        $check_in = strtotime($current_date . ' ' . $device_log[0]->signinTime);
                                        $check_out = strtotime($current_date . ' ' . $device_log[1]->signinTime);

                                        if ($device_log[0]->branch_id != "") {
                                            $check_in_branch = $device_log[0]->branch_id;
                                        }

                                        if ($device_log[1]->branch_id != "") {
                                            $check_out_branch = $device_log[1]->branch_id;
                                        }

                                        if (($check_in <= $shift_check_in) && ($check_out >= $shift_check_out)) {
                                            $remark = "P";
                                            $class = "present";
                                        } else {
                                            $remark = "L";
                                            $class = "late";

                                            if ($leave) {
                                                $leave_type = $this->_getLeaveType($leave);
                                                $remark = $leave_type['remark'];
                                                $class = $leave_type['class'];
                                            }
                                        }
                                    } else if (count($device_log) > 2) {
                                        $count_device_log = count($device_log);
                                        $count = $count_device_log - 1;
                                        if ($device_log[0]->branch_id != "") {
                                            $check_in_branch = $device_log[0]->branch_id;
                                        }

                                        $check_in = strtotime($current_date . ' ' . $device_log[0]->signinTime);

                                        if ($count_device_log == 3) {
                                            $break_out = strtotime($current_date . ' ' . $device_log[1]->signinTime);
                                            $check_out = strtotime($current_date . ' ' . $device_log[2]->signinTime);

                                            if ($device_log[2]->branch_id != "") {
                                                $check_out_branch = $device_log[2]->branch_id;
                                            }
                                        } else {
                                            $break_out = strtotime($current_date . ' ' . $device_log[1]->signinTime);
                                            $break_in = strtotime($current_date . ' ' . $device_log[2]->signinTime);

                                            $check_out = strtotime($current_date . ' ' .
                                                $device_log[$count]->signinTime);

                                            if ($device_log[$count]->branch_id != "") {
                                                $check_out_branch = $device_log[$count]->branch_id;
                                            }

                                            if (($check_in <= $shift_check_in) && ($check_out >= $shift_check_out)) {
                                                $remark = "P";
                                                $class = "present";
                                            } else {
                                                $remark = "L";
                                                $class = "late";

                                                if ($leave) {
                                                    $leave_type = $this->_getLeaveType($leave);
                                                    $remark = $leave_type['remark'];
                                                    $class = $leave_type['class'];
                                                }
                                            }
                                        }
                                    }
                                } else {
                                    // if break is disable
                                    $device_log_count = count($device_log);
                                    $count = $device_log_count - 1;

                                    if ($device_log_count == 1) {
                                        $remark = 'L';
                                        $class = 'late';
                                        $check_in = strtotime($current_date . ' ' . $device_log[0]->signinTime);
                                    } else if ($device_log_count >= 2) {


                                        $check_in = strtotime($current_date . ' ' . $device_log[0]->signinTime);

                                        if ($device_log[0]->branch_id != "") {
                                            $check_in_branch = $device_log[0]->branch_id;
                                        }


                                        if ($shift_type == "ROUNDTIME" && ($shift_check_in > $shift_check_out)) {
                                            // @todo:- get next day first data
                                            $next_day = date("Y-m-d", strtotime($current_date . ' + 1 day'));

                                            $check_in = strtotime($current_date . ' ' . $device_log[$count]->signinTime);
                                            $check_out = strtotime($current_date . ' ' . $device_log[$count]->signinTime);
                                        } else {
                                            $check_out = strtotime($current_date . ' ' . $device_log[$count]->signinTime);
                                        }

                                        if ($device_log[$count]->branch_id != "") {
                                            $check_out_branch = $device_log[$count]->branch_id;
                                        }

                                        if (($check_in <= $shift_check_in) && ($check_out >= $shift_check_out)) {
                                            $remark = "P";
                                            $class = "present";
                                        } else {
                                            $remark = "L";
                                            $class = "late";

                                            if ($leave) {
                                                $leave_type = $this->_getLeaveType($leave);
                                                $remark = $leave_type['remark'];
                                                $class = $leave_type['class'];
                                            }
                                        }
                                    } else {
                                        if ($shift_type == "ROUNDTIME" && (strtotime($shift_check_in) > strtotime($shift_check_out))) {
                                            // @todo:- get next day first data
                                            $next_day = date("Y-m-d", strtotime($current_date . ' + 1 day'));

                                            $check_in = strtotime($current_date . ' ' . $device_log[$count]->signinTime);
                                            $check_out = strtotime($current_date . ' ' . $device_log[$count]->signinTime);
                                        } else {
                                            $check_out = strtotime($current_date . ' ' . $device_log[$count]->signinTime);
                                        }

                                        if (($check_in <= $shift_check_in) && ($checkout >= $shift_check_out)) {
                                            $remark = "P";
                                            $class = "present";
                                        } else {
                                            $remark = 'A2';
                                            $class = 'absent';
                                        }
                                    }
                                }
                            }
                        }
                    }
                    // end if data

                    if ($leave) {
                        $leave_type = $this->_getLeaveType($leave);
                        $remark = $leave_type['remark'];
                        $class = $leave_type['class'];
                    }

                    $temp_attendance = array();

                    $temp_attendance = array(
                        "company_id" => $company_id,
                        "employee_id" => $employee_id,
                        "checkin" => date("Y-m-d H:i:s", @$check_in),
                        "remarks" => $remark,
                        "css_class" => $class,
                        "checkin_branch_id" => @$check_in_branch,
                        "checkout_branch_id" => @$check_out_branch,
                    );

                    if ($remark != 'A' && $check_out != "") {
                        $temp_start = date_create(date("Y-m-d H:i:s", @$check_in));
                        $temp_end = date_create(date("Y-m-d H:i:s", @$check_out));
                        $diff = date_diff($temp_start, $temp_end);

                        if ($diff->h >= $half_day_hour) {
                            $present_days = '1';
                        } else {
                            $present_days = '0.5';
                        }
                    } else {
                        $present_days = '0';
                    }

                    $temp_attendance['present_days'] = $present_days;

                    if (@$check_out) {
                        $temp_attendance['checkout'] = date("Y-m-d H:i:s", @$check_out);
                    }

                    if (@$break_out) {
                        $temp_attendance['break_out'] = date("Y-m-d H:i:s", @$break_out);
                    }

                    if (@$break_in) {
                        $temp_attendance['break_in'] = date("Y-m-d H:i:s", @$break_in);
                    }

                    if ($holiday) {
                        $temp_attendance['present_days'] = '0';
                        $temp_attendance['remarks'] = 'H';
                        $temp_attendance['css_class'] = 'holiday';
                    }

                    if (in_array($day_of_week, $weekend) || $day_of_week == $weekend) {
                        $att_temp['present_days'] = '0';
                        $att_temp['remarks'] = 'W';
                        $att_temp['css_class'] = 'weekend';
                    }

                    if ($att_temp['css_class'] == 'absent' || $att_temp['css_class'] == 'weekend' || $att_temp['css_class'] == 'holiday') {
                        $check_awa = $this->employee_model->check_ret_present($employee_id, $current_date);
                        //echo 'retrun: ' . $check_awa . "<br>";
                        if (!$check_awa) {
                            $att_temp['present_days'] = '0';
                            $att_temp['remarks'] = 'A3';
                            $att_temp['css_class'] = 'absent';
                        }
                    }

                    // check if attendance exists
                    $check_attendance = $this->db
                        ->where(array(
                            "date(checkin)" => date("Y-m-d", $current_date),
                            "company_id" => $company_id,
                            "employee_id" => $employee_id
                        ))
                        ->get("attendance")
                        ->row();

                    $absentHour = null;
                    $shift_hour = null;
                    if ($late_deduction == "yes") {
                        $absentHour = 0;
                        $shiftCheckIn = new DateTime($shift_check_in);
                        $shiftCheckOut = new DateTime($shift_check_out);
                        $checkin = $check_in;
                        $checkout = $this->_check_checkout($check_out, $check_in, $shiftCheckOut);

                        $lateArrival = $shift->late_arrival;
                        $earlyDeparture = $shift->early_departure;
                        $half_attendance = $shift->daily_attendance * 60;
                        $totalWorkedHour = strtotime($checkout) - strtotime($checkin);
                        $shiftArr = $shiftCheckIn->diff($shiftCheckOut);
                        $work_hour = $totalWorkedHour / 60;
                        $shift_hour = $shiftArr->h * 60 + $shiftArr->i;
                        $total_shift_hour = $shift_hour;

                        if ($lateArrival) {
                            $shift_hour = $shift_hour - $lateArrival;
                        }

                        if ($earlyDeparture) {
                            $shift_hour = $shift_hour - $earlyDeparture;
                        }

                        if ($work_hour > 0) {
                            if ($work_hour > $half_attendance && $work_hour <= $shift_hour) {
                                $absentHour = $shift_hour - $work_hour;
                            }
                        }
                    }


                    if (in_array($day_of_week, $weekend) || $day_of_week == $weekend || $holiday) {
                        if ($check_attendance->checkin != '' && $check_attendance->checkout != '') {
                            $temp_start = date_create(date('Y-m-d H:i:s', strtotime($check_attendance->checkin)));
                            $temp_end = date_create(date('Y-m-d H:i:s', strtotime($check_attendance->checkout)));
                            $diff = date_diff($temp_end, $temp_start);
                            if ($diff->h >= 3) {
                                $extras = 1;
                            } else if ($diff->h > 1.5) {
                                $extras = 0.5;
                            } else {
                                $extras = 0;
                            }
                        }
                    }

                    if ($check_attendance) {
                        if ($check_attendance->edited_by == '') {
                            $update_data = array(
                                "remarks" => $temp_attendance['remarks'],
                                "css_class" => $temp_attendance['css_class'],
                                "checkin" => $temp_attendance['checkin'],
                            );

                            if ($temp_attendance['checkout'] != "") {
                                $update_data['checkout'] = $temp_attendance['checkout'];
                            }

                            if ($temp_attendance['break_out'] != "") {
                                $update_data['break_out'] = $temp_attendance['break_out'];
                            }

                            if ($temp_attendance['break_in'] != "") {
                                $update_data['break_in'] = $temp_attendance['break_in'];
                            }

                            if ($temp_attendance['checkin_branch_id'] != "") {
                                $update_data['checkin_branch_id'] = $temp_attendance['branch_check_in'];
                            }

                            if ($temp_attendance['checkout_branch_id'] != "") {
                                $update_data['checkout_branch_id'] = $temp_attendance['checkout_branch_id'];
                            }

                            if ($temp_attendance['present_days'] != "") {
                                $update_data['present_days'] = $temp_attendance['present_days'];
                            }

                            $update_data['extra'] = $extras;
                            $update_data['absent_hour'] = $absentHour;
                            $update_data['shift_hour'] = $total_shift_hour;

                            $this->db->update('attendance_backup', $update_data, array(
                                "attendance_id" => $check_attendance->attendance_id,
                            ));
                        } else {
                            $new_update_data['extra'] = $extras;
                            $new_update_data['absent_hour'] = $absentHour;
                            $new_update_data['shift_hour'] = $total_shift_hour;

                            $this->db->update('attendance_backup', $new_update_data, array(
                                "attendance_id" => $check_attendance->attendance_id,
                            ));
                        }
                    } else {

                        $temp_attendance['extra'] = $extras;
                        $temp_attendance['absent_hour'] = $absentHour;
                        $temp_attendance['shift_hour'] = $total_shift_hour;

                        $this->db->insert("attendance_backup", $temp_attendance);
                    }
                }
            }

            echo "success";
        } catch (Exception $exception) {
            print_r($exception->getMessage());
            exit;
        }
    }



    public function index_bkup()
    {

        try {


            ini_set('max_execution_time', 0);
            set_time_limit(0);
            ini_set("memory_limit", "5024M");


            $user_data = $this->session->userdata();

            $company_id = '101';

            $check_exist = $this->db->where("company_id", $company_id)->get("attendance_sync");
            if ($check_exist->num_rows() > 0) {
                $last_sync_date = date("Y-m-d", strtotime($check_exist->row()->last_sync_date));
                $current_date = date("Y-m-d");
                $updated_date = $check_exist->row()->updated_on;
            } else {
                $last_sync_date = $current_date = date("Y-m-d");
            }






            $datefrom = '2021-12-01';
            $dateto = '2021-12-31';


            $company_id = 101;
            $allemployee = $this->employee_model->get_employee_list($company_id);
            #displayArr($allemployee);
            $start = strtotime($datefrom);
            $attend2 = array();
            if ($allemployee) {
                foreach ($allemployee as $ae) {
                    $alldevicedata = $this->db->where(
                        array(
                            'enrollNumber' => $ae->id,
                            'companyid' => $company_id,
                            'status' => '0',
                            'date(signinDate) >=' => date('Y-m-d', strtotime($datefrom)),
                            'date(signinDate) <=' => date('Y-m-d', strtotime($dateto))
                        )
                    )->order_by('enrollNumber, signinDate, signinTime', 'ASC')
                        ->group_by('signinTime')->get('devicelog')->result();

                    $i = 0;
                    $attend = array();
                    $checks = array();
                    //displayArr($alldevicedata);

                    if ($alldevicedata) {
                        foreach ($alldevicedata as $k => $add) {
                            $checks[$add->signinDate]['data'][] = $add->signinTime;
                            $checks[$add->signinDate]['branch_id'][] = $add->branch_id;
                        }
                    }


                    $empid = $ae->empid;
                    $attend[$empid] = $checks;
                    //$start = strtotime("+1 day", $start);
                    $start = strtotime($datefrom);
                    //displayArr($attend);
                    while ($start <= strtotime($dateto)) {

                        $shift_id = $this->shift_model->get_employee_shift(date('Y-m-d', $start), $ae->empid);
                        $shift_detail = $this->shift_model->find($shift_id);

                        if (empty($attend[$empid][date('Y-m-d', $start)]['data'])) {
                            // if there is no fingerprint
                            $attend2[$empid][date('Y-m-d', $start)] = null;
                            $attend2[$empid][date('Y-m-d', $start)]['shift_id'] = $shift_id;
                        } else {
                            // if there is fingerprint
                            $attend2[$empid][date('Y-m-d', $start)] = $attend[$empid][date('Y-m-d', $start)];
                            $attend2[$empid][date('Y-m-d', $start)]['shift'] = $shift_detail;
                            $attend2[$empid][date('Y-m-d', $start)]['shift_id'] = $shift_id;
                            //$attend2[$empid][date('Y-m-d', $start)]['branch_id'] = $
                        }
                        $start = strtotime("+1 day", $start);
                    }

                    ksort($attend2[$empid]);
                }
            }


            $attendance = array();
            if ($attend2) {
                foreach ($attend2 as $empID => $emp_attend) {
                    foreach ($emp_attend as $att_date => $attval) {
                        //displayArr($attval['shift']);
                        $current_date = strtotime($att_date);
                        $breakout = '';
                        $breakin = '';
                        $checkin = '';
                        $checkout = '';
                        $remark = '';
                        $class = '';
                        $checkin_branch = '';
                        $checkout_branch = '';
                        $present_days = '0';
                        $extras = 0;
                        $total_shift_hour = 0;


                        if (@$attval['shift']->single_punch == 'YES') {
                            if (count(@$attval['data']) > 0) {
                                $remark = "P";
                                $class = "present";
                                $checkin = strtotime($att_date . ' ' . $attval['data'][0]);
                                if (@$attval['branch_id'][0] != '') $checkin_branch = $attval['branch_id'][0];
                                if (count(@$attval['data']) <= 1) {
                                    $checkout = '';
                                } else {
                                    $checkout = strtotime($att_date . ' ' . $attval['data'][(count($attval['data']) - 1)]);
                                    if (@$attval['branch_id'][count($attval['data']) - 1] != '') $checkout_branch = $attval['branch_id'][count($attval['data']) - 1];
                                }
                                $present_days = '1';
                            } else {
                                $remark = "A";
                                $class = "absent";
                                $checkin = $current_date;
                                $present_days = '0';
                            }
                        } else if (@$attval['shift_id'] == '-1') {
                            // employee has day off in the roster;
                            $remark = "DO";
                            $class = "dayoff";
                            $checkin = $current_date;
                            $present_days = '0';
                        } else if (empty($attval['data'])) {

                            $shift = $this->shift_model->find($attval['shift_id']);
                            // if there is no data it happens when: weekend, leave or holiday;
                            $dayofweek = date('w', $current_date);

                            $leave = $this->employee_model->check_leave($empID, $att_date, $company_id);

                            $shiftWeekend = @$shift->weekend;

                            $weekendArr = json_decode($shiftWeekend, true);
                            if (is_array($weekendArr)) {
                                $weekend = @$weekendArr['weekend'];
                            } else {
                                $weekend = array();
                            }


                            //if ($dayofweek == @$shift->weekend) {
                            if (in_array($dayofweek, $weekend) || $dayofweek == $shiftWeekend) {
                                $remark = "W";
                                $class = 'weekend';
                            } else if ($this->employee_model->check_holiday($att_date, $company_id)) {
                                $remark = "H";
                                $class = 'holiday';
                            } else if ($leave) {
                                $leave_type = $this->db->where('leave_typeID', @$leave->leave_type)->get('leave_type')->row();
                                $remark = (@$leave_type->leave_code != '') ? $leave_type->leave_code : 'OL';
                                $class = 'leave';
                            } else {
                                $remark = 'A';
                                $class = 'absent';
                            }

                            $out_checkin = date('Y-m-d', $current_date);
                            $outarr = explode('-', $out_checkin);
                            if (!$this->employee_model->check_ret_present($empID, $outarr[0], $outarr[1], $outarr[2])) {
                                $remark = 'A';
                                $class = 'absent';
                            }
                            $checkin = $current_date;
                            $present_days = '0';
                        } else {
                            // if there is data;
                            if ($attval['shift']->enable_break == 'YES') {
                                //break is there in the shift

                                /*
										if (count($attval['data']) == 1) {

											// === === === === === === === === === === ===
											// === === === === === === === === === === ===
											// === === === === === === === === === === ===
											// === === === === === === === === === === ===
											// === === === === === === === === === === ===
											// === === === === === === === === === === ===
											// === === === === === === === === === === ===
											// === === === === === === === === === === ===
											// === === === === === === === === === === ===


											$checkin = date('H:i', strtotime('+' . $attval['shift']->late_arrival . " minutes", strtotime($attval['shift']->checkin)));

											if ($attval['shift']->mark_single_punch == "P") {
												$remark = 'P';
												$class = 'present';
											} elseif ($attval['shift']->mark_single_punch == "A") {
												$remark = "A";
												$class = "absent";
											} elseif ($attval['shift']->mark_single_punch == "HP") {
												$remark = "HP";
												$class = "half_present";
											} else {
												$remark = "L";
												$class = "late";
											}


										} else
											*/
                                if (count($attval['data']) < 2) {
                                    if ($att_date == date('Y-m-d')) {
                                        $checkin = date('H:i', strtotime('+' . $attval['shift']->late_arrival . " minutes", strtotime($attval['shift']->checkin)));

                                        if (strtotime($att_date . ' ' . $attval['data'][0]) >= strtotime($att_date . ' ' . $checkin)) {
                                            $remark = 'P';
                                            $class = 'present';
                                            $checkin = strtotime($att_date . ' ' . $attval['data'][0]);
                                            if (@$attval['branch_id'][0] != '') $checkin_branch = $attval['branch_id'][0];
                                        }
                                    } else {
                                        $remark = 'L';
                                        $class = 'late';
                                        $checkin = strtotime($att_date . ' ' . $attval['data'][0]);
                                        if (@$attval['branch_id'][0] != '') $checkin_branch = $attval['branch_id'][0];

                                        $leave = $this->employee_model->check_leave($empID, $att_date, $company_id);
                                        if ($leave) {
                                            $leave_type = $this->db->where('leave_typeID', @$leave->leave_type)->get('leave_type')->row();
                                            $remark = (@$leave_type->leave_code != '') ? $leave_type->leave_code : 'OL';
                                            $class = 'leave';
                                        }
                                    }
                                } else if (count($attval['data']) == 2) {
                                    $checkin = strtotime($att_date . ' ' . $attval['data'][0]);
                                    $checkout = strtotime($att_date . ' ' . $attval['data'][1]);

                                    if (@$attval['branch_id'][0] != '') $checkin_branch = $attval['branch_id'][0];
                                    if (@$attval['branch_id'][1] != '') $checkout_branch = $attval['branch_id'][1];

                                    $vcheckin = date('H:i', strtotime('+' . $attval['shift']->late_arrival . " minutes", strtotime($attval['shift']->checkin)));
                                    $vcheckout = date('H:i', strtotime('-' . $attval['shift']->early_departure . " minutes", strtotime($attval['shift']->checkout)));
                                    $shift_checkin = strtotime($att_date . ' ' . $vcheckin);
                                    $shift_checkout = strtotime($att_date . ' ' . $vcheckout);

                                    if (($checkin <= $shift_checkin) && ($checkout >= $shift_checkout)) {
                                        $remark = "P";
                                        $class = "present";
                                    } else {
                                        $remark = 'L';
                                        $class = 'late';

                                        $leave = $this->employee_model->check_leave($empID, $att_date, $company_id);
                                        if ($leave) {
                                            $leave_type = $this->db->where('leave_typeID', @$leave->leave_type)->get('leave_type')->row();
                                            $remark = (@$leave_type->leave_code != '') ? $leave_type->leave_code : 'OL';
                                            $class = 'leave';
                                        }
                                    }
                                } else if (count($attval['data']) > 2) {
                                    $checkin = strtotime($att_date . ' ' . $attval['data'][0]);
                                    if (@$attval['branch_id'][0] != '') $checkin_branch = $attval['branch_id'][0];
                                    if (count($attval['data']) == 3) {
                                        $breakout = strtotime($att_date . ' ' . $attval['data'][1]);
                                        $checkout = strtotime($att_date . ' ' . $attval['data'][2]);
                                        if (@$attval['branch_id'][2] != '') $checkout_branch = $attval['branch_id'][2];
                                    } else {
                                        $breakout = strtotime($att_date . ' ' . $attval['data'][1]);
                                        $breakin = strtotime($att_date . ' ' . $attval['data'][2]);
                                        $checkout = strtotime($att_date . ' ' . $attval['data'][(count($attval['data']) - 1)]);
                                        if (@$attval['branch_id'][(count($attval['data']) - 1)] != '') $checkout_branch = $attval['branch_id'][(count($attval['data']) - 1)];
                                    }

                                    $vcheckin = date('H:i', strtotime('+' . $attval['shift']->late_arrival . " minutes", strtotime($attval['shift']->checkin)));
                                    $vcheckout = date('H:i', strtotime('-' . $attval['shift']->early_departure . " minutes", strtotime($attval['shift']->checkout)));
                                    $shift_checkin = strtotime($att_date . ' ' . $vcheckin);
                                    $shift_checkout = strtotime($att_date . ' ' . $vcheckout);
                                    if (($checkin <= $shift_checkin) && ($checkout >= $shift_checkout)) {
                                        $remark = "P";
                                        $class = "present";
                                    } else {
                                        $remark = 'L';
                                        $class = 'late';

                                        $leave = $this->employee_model->check_leave($empID, $att_date, $company_id);
                                        if ($leave) {
                                            $leave_type = $this->db->where('leave_typeID', @$leave->leave_type)->get('leave_type')->row();
                                            $remark = (@$leave_type->leave_code != '') ? $leave_type->leave_code : 'OL';
                                            $class = 'leave';
                                        }
                                    }
                                }
                            } // end if enable break is yes else {
                            else {
                                $checkin = strtotime($att_date . ' ' . $attval['data'][0]);
                                if (@$attval['branch_id'][0] != '') $checkin_branch = $attval['branch_id'][0];
                                $vcheckin = date('H:i', strtotime('+' . $attval['shift']->late_arrival . " minutes", strtotime($attval['shift']->checkin)));
                                $vcheckout = date('H:i', strtotime('-' . $attval['shift']->early_departure . " minutes", strtotime($attval['shift']->checkout)));
                                $shift_checkin = strtotime($att_date . ' ' . $vcheckin);
                                $shift_checkout = strtotime($att_date . ' ' . $vcheckout);

                                if ($att_date == date('Y-m-d')) {
                                    if (count($attval['data']) > 1) {
                                        $checkout = strtotime($att_date . ' ' . @$attval['data'][(count($attval['data']) - 1)]);
                                        if (($checkin <= $shift_checkin) && ($checkout >= $shift_checkout)) {
                                            $remark = "P";
                                            $class = "present";
                                        } else {
                                            $remark = 'L';
                                            $class = 'late';

                                            $leave = $this->employee_model->check_leave($empID, $att_date, $company_id);
                                            if ($leave) {
                                                $leave_type = $this->db->where('leave_typeID', @$leave->leave_type)->get('leave_type')->row();
                                                $remark = (@$leave_type->leave_code != '') ? $leave_type->leave_code : 'OL';
                                                $class = 'leave';
                                            }
                                        }
                                    } else {
                                        if ($checkin >= $shift_checkin) {
                                            $remark = 'P';
                                            $class = 'present';
                                        } else {
                                            $remark = 'A';
                                            $class = 'absent';
                                        }
                                    }
                                } else {
                                    if (count($attval['data']) == 1) {
                                        $remark = 'L';
                                        $class = 'late';
                                    } else if (count($attval['data']) >= 2) {

                                        if ($attval['shift']->type == 'ROUNDTIME' && (strtotime($attval['shift']->checkin) > strtotime($attval['shift']->checkout))) {
                                            //$checkout = strtotime($att_date . ' ' . @$attval['data'][(count($attval['data']) - 1)]);
                                            $next_day = date('Y-m-d', strtotime($att_date . ' + 1 day'));
                                            $checkin = strtotime($att_date . ' ' . @$attval['data'][(count($attval['data']) - 1)]);
                                            $checkout = strtotime($emp_attend[$next_day]['data'][0]);
                                        } else {
                                            $checkout = strtotime($att_date . ' ' . @$attval['data'][(count($attval['data']) - 1)]);
                                        }

                                        // $checkout = strtotime($att_date . ' ' . @$attval['data'][(count($attval['data']) - 1)]);
                                        if (@$attval['branch_id'][(count($attval['data']) - 1)] != '') $checkout_branch = $attval['branch_id'][(count($attval['data']) - 1)];
                                        if (($checkin <= $shift_checkin) && ($checkout >= $shift_checkout)) {
                                            $remark = "P";
                                            $class = "present";
                                        } else {
                                            $remark = 'L';
                                            $class = 'late';

                                            $leave = $this->employee_model->check_leave($empID, $att_date, $company_id);
                                            if ($leave) {
                                                $leave_type = $this->db->where('leave_typeID', @$leave->leave_type)->get('leave_type')->row();
                                                $remark = (@$leave_type->leave_code != '') ? $leave_type->leave_code : 'OL';
                                                $class = 'leave';
                                            }
                                        }
                                    } else {
                                        if ($attval['shift']->type == 'ROUNDTIME' && (strtotime($attval['shift']->checkin) > strtotime($attval['shift']->checkout))) {
                                            //$checkout = strtotime($att_date . ' ' . @$attval['data'][(count($attval['data']) - 1)]);
                                            $next_day = date('Y-m-d', strtotime($att_date . ' + 1 day'));
                                            $checkin = strtotime($att_date . ' ' . @$attval['data'][(count($attval['data']) - 1)]);
                                            $checkout = strtotime($emp_attend[$next_day]['data'][0]);
                                        } else {
                                            $checkout = strtotime($att_date . ' ' . @$attval['data'][(count($attval['data']) - 1)]);
                                        }

                                        if (($checkin <= $shift_checkin) && ($checkout >= $shift_checkout)) {
                                            $remark = "P";
                                            $class = "present";
                                        } else {
                                            $remark = 'A';
                                            $class = 'absent';
                                        }
                                    }
                                }
                            }
                        } //end if there is data


                        $empl = $this->employee_model->find($empID);
                        $eshift = $this->shift_model->find($empl->shift);
                        $half_day_hour = ($eshift->daily_attendance == '') ? 5 : $eshift->daily_attendance;

                        $leave = $this->employee_model->check_leave($empID, $att_date, $company_id);
                        //displayArr($leave);
                        //echo('<br>' . $empID . ',' . $att_date . ',' . $company_id);
                        if ($leave) {
                            $leave_type = $this->db->where('leave_typeID', @$leave->leave_type)->get('leave_type')->row();
                            $remark = (@$leave_type->leave_code != '') ? $leave_type->leave_code : 'OL';
                            $class = 'leave';
                        }

                        $att_temp = array();
                        $att_temp = array(
                            'company_id' => $company_id,
                            'employee_id' => $empl->id,
                            'checkin' => date('Y-m-d H:i:s', $checkin),
                            'remarks' => $remark,
                            'css_class' => $class
                        );
                        //displayArr($att_temp);
                        if ($remark != 'A' && @$checkout != '') {
                            //echo $checkin . "-" . $checkout . "<br>";
                            $kstart = date_create(date('Y-m-d H:i:s', @$checkin));
                            $kend = date_create(date('Y-m-d H:i:s', @$checkout));
                            $diff = date_diff($kstart, $kend);
                            // echo $diff->h . '>=' . $half_day_hour . ' =' . $empl->id . '--' . date('Y-m-d', $checkin) . '<br>';
                            if ($diff->h >= $half_day_hour) {
                                $present_days = '1';
                            } else {
                                $present_days = '0.5';
                            }
                        } else {
                            $present_days = '0';
                        }
                        $att_temp['present_days'] = $present_days;

                        if (@$checkout) $att_temp['checkout'] = date('Y-m-d H:i:s', $checkout);
                        if (@$breakout) $att_temp['break_out'] = date('Y-m-d H:i:s', $breakout);
                        if (@$breakin) $att_temp['break_in'] = date('Y-m-d H:i:s', $breakin);

                        if ($checkin_branch != '') $att_temp['checkin_branch_id'] = $checkin_branch;
                        if ($checkout_branch != '') $att_temp['checkout_branch_id'] = $checkout_branch;

                        if ($this->employee_model->check_holiday(date('Y-m-d', @$checkin), $company_id)) {
                            $att_temp['present_days'] = '0';
                            $att_temp['remarks'] = 'H';
                        }
                        //displayARr($att_temp);
                        $dayofweek = date('w', $current_date);


                        $shiftWeekend = @$shift->weekend;

                        $weekendArr = json_decode($shiftWeekend, true);
                        if (is_array($weekendArr)) {
                            $weekend = @$weekendArr['weekend'];
                        } else {
                            $weekend = array();
                        }


                        if (in_array($dayofweek, $weekend) || $dayofweek == $shiftWeekend) {
                            $att_temp['present_days'] = '0';
                            $att_temp['remarks'] = 'W';
                            $att_temp['css_class'] = 'weekend';
                        }


                        if ($att_temp['css_class'] == 'absent' || $att_temp['css_class'] == 'weekend' || $att_temp['css_class'] == 'holiday') {
                            $myreturn = $this->employee_model->check_ret_present($empl->id, $att_date);
                            //echo 'retrun: ' . $myreturn . "<br>";
                            if (!$myreturn) {
                                $att_temp['present_days'] = '0';
                                $att_temp['remarks'] = 'A';
                                $att_temp['css_class'] = 'absent';
                            }
                        }

                        $attendance_ins[] = $att_temp;
                    }
                }
            }


            if ($attendance_ins) {
                $batch_insert = array();
                foreach ($attendance_ins as $index => $ai) {
                    $early_attend = $this->db->where(
                        array(
                            'date(checkin)' => date('Y-m-d', strtotime($ai['checkin'])),
                            'company_id' => $company_id,
                            'employee_id' => $ai['employee_id']
                        )
                    )->get('attendance')->row();

                    $extras = 0;

                    // === === === === === === === === === === === === ===
                    // === === === === === === === === === === === === ===
                    // === === === === === === === === === === === === ===
                    // === === === ===   LATE CALCULATION  === === === ===
                    // === === === === === === === === === === === === ===
                    // === === === === === === === === === === === === ===
                    // === === === === === === === === === === === === ===
                    $late_deduction = "yes";
                    $absentHour = null;
                    $shift_hour = null;
                    if ($late_deduction == "yes") {
                        $absentHour = 0;
                        $shiftCheckIn = new DateTime($eshift->checkin);
                        $shiftCheckOut = new DateTime($eshift->checkout);
                        $checkin = $ai['checkin'];
                        $checkout = $this->_check_checkout($ai['checkout'], $ai['checkin'], $shiftCheckOut);

                        $lateArrival = $eshift->late_arrival;
                        $earlyDeparture = $eshift->early_departure;
                        $half_attendance = $eshift->daily_attendance * 60;
                        $totalWorkedHour = strtotime($ai['checkout']) - strtotime($ai['checkin']);
                        $shiftArr = $shiftCheckIn->diff($shiftCheckOut);
                        $work_hour = $totalWorkedHour / 60;
                        $shift_hour = $shiftArr->h * 60 + $shiftArr->i;
                        $total_shift_hour = $shift_hour;

                        if ($lateArrival) {
                            $shift_hour = $shift_hour - $lateArrival;
                        }

                        if ($earlyDeparture) {
                            $shift_hour = $shift_hour - $earlyDeparture;
                        }

                        if ($work_hour > 0) {
                            if ($work_hour > $half_attendance && $work_hour <= $shift_hour) {
                                $absentHour = $shift_hour - $work_hour;
                            }
                        }
                    }

                    if ($early_attend) {
                        $dayofweek = date('w', strtotime($ai['checkin']));

                        if (($dayofweek == @$eshift->weekend) || ($this->employee_model->check_holiday($ai['checkin'], $company_id))) {
                            if ($early_attend->checkin != '' && $early_attend->checkout != '') {
                                $kstart = date_create(date('Y-m-d H:i:s', strtotime($early_attend->checkin)));
                                $kend = date_create(date('Y-m-d H:i:s', strtotime($early_attend->checkout)));
                                $diff = date_diff($kend, $kstart);

                                if ($diff->h >= 3) {
                                    $extras = 1;
                                } else if ($diff->h > 1.5) {
                                    $extras = 0.5;
                                } else {
                                    $extras = 0;
                                }
                            }
                        }


                        if ($early_attend->edited_by == '') {
                            //echo $ai['checkin'] . ':' .
                            $update_ai = array(
                                'remarks' => $ai['remarks'],
                                'css_class' => $ai['css_class'],
                                'checkin' => $ai['checkin'],
                            );

                            if (@$ai['checkout'] != '') $update_ai['checkout'] = $ai['checkout'];
                            if (@$ai['break_out'] != '') $update_ai['break_out'] = $ai['break_out'];
                            if (@$ai['break_in'] != '') $update_ai['break_in'] = $ai['break_in'];

                            if (@$ai['checkin_branch_id'] != '') $update_ai['checkin_branch_id'] = $ai['checkin_branch_id'];
                            if (@$ai['checkout_branch_id'] != '') $update_ai['checkout_branch_id'] = $ai['checkout_branch_id'];
                            if (@$ai['present_days'] != '') $update_ai['present_days'] = $ai['present_days'];
                            $update_ai['extra'] = $extras;
                            $update_ai['absent_hour'] = $absentHour;
                            $update_ai['shift_hour'] = $total_shift_hour;

                            $this->db->update(
                                'attendance',
                                $update_ai,
                                array(
                                    'attendance_id' => $early_attend->attendance_id
                                )
                            );
                        } else {
                            $nai['extra'] = $extras;
                            $nai['absent_hour'] = $absentHour;
                            $nai['shift_hour'] = $total_shift_hour;
                            $this->db->update(
                                'attendance',
                                $nai,
                                array(
                                    'attendance_id' => $early_attend->attendance_id
                                )
                            );
                        }
                    } else {
                        $dayofweek = date('w', strtotime($checkin));
                        if (($dayofweek == @$eshift->weekend) || ($this->employee_model->check_holiday($checkin, $company_id))) {
                            if ($ai['checkin'] != '' && $ai['checkout'] != '') {
                                $kstart = date_create(date('Y-m-d H:i:s', @$checkin));
                                $kend = date_create(date('Y-m-d H:i:s', @$checkout));
                                $diff = date_diff($kstart, $kend);
                                if ($diff->h >= 3) {
                                    $extras = 1;
                                } else if ($diff->h > 1.5) {
                                    $extras = 0.5;
                                } else {
                                    $extras = 0;
                                }
                            }
                        }
                        $ai['extra'] = $extras;
                        $ai['absent_hour'] = $absentHour;
                        $ai['shift_hour'] = $total_shift_hour;
                        $this->db->insert("attendance", $ai);
                        //$batch_insert[] = $ai;
                    }
                }

                //						if (!empty($batch_insert)) {
                //							$this->db->insert_batch('attendance', $batch_insert);
                //						}
            }

            // last sync date add

            $insert_data = array(
                "last_sync_date" => $dateto,
                "created_by" => $user_data['user_id'],
                "company_id" => $company_id
            );


            if ($check_exist->num_rows() > 0) {
                $this->db->where("company_id", $company_id)->update("attendance_sync", $insert_data);
            } else {
                $this->db->insert("attendance_sync", $insert_data);
            }


            echo "success";


            exit;
        } catch (Exception $exception) {
            print_r($exception->getMessage());
            exit;
        }
    }


    public function _check_checkout($checkout, $checkin, $shift_checkout)
    {
        $checkOutYear = date("Y-m-d", strtotime($checkin));
        $checkOut = date("H:i:s", strtotime($checkout));
        $newCheckout = new DateTime($checkOut);
        $shift_his = $shift_checkout->format("H:i:s");

        if ($checkout == "") {
            return $checkOutYear . " " . $shift_his;
        }

        if ($newCheckout > $shift_checkout) {
            return $checkOutYear . " " . $shift_his;
        } else {
            return $checkout;
        }
    }

    public function _createOrUpdateAttendanceSync($company_id, $user_id, $last_sync_date)
    {
        $check_exist = $this->db->where("company_id", $company_id)->get("attendance_sync");
        $insert_data = array(
            "last_sync_date" => $last_sync_date,
            "created_by" => $user_id,
            "company_id" => $company_id
        );

        if ($check_exist->num_rows() > 0) {
            $this->db->where("company_id", $company_id)->update("attendance_sync", $insert_data);
        } else {
            $this->db->insert("attendance_sync", $insert_data);
        }
    }

    public function _getEmployee($company_id)
    {
        return $this->db
            ->where(array(
                "company" => $company_id,
                "required_attendance" => "yes",
                "status" => "ACTIVE",
            ))
            ->get("employee")
            ->result();
    }

    public function _getDateInterval($start, $end)
    {
        $begin = new DateTime($start);
        $end = new DateTime($end);

        $interval = DateInterval::createFromDateString('1 day');
        return new DatePeriod($begin, $interval, $end);
    }

    public function _getEmployeeShift($employee_id, $current_date)
    {
        $company_id = 101;
        $employee = $this->db
            ->where(array(
                "company" => $company_id,
                "id" => $employee_id,
            ))
            ->get("employee")
            ->row();


        echo "<pre>";
        print_r($this->db->last_query());
        print_r($employee_id);
        print_r($employee);
        echo "</pre>";


        $shift_roster = $this->db
            ->where("employee_id", $employee->empid)
            ->where("roster_date", $current_date)
            ->get("shift_roster");
        if ($shift_roster->num_rows() > 0) {
            $shift_id = $shift_roster->row()->roster_shift_id;
        } else {
            $shift_id = $employee->shift;
        }
        return $this->db
            ->where(array(
                "shift_id" => $shift_id,
                "company_id" => $company_id
            ))
            ->get("shift")
            ->row();
    }

    public function _getDeviceLog($employee_id, $current_date)
    {
        $company_id = 101;
        return $this->db
            ->where(array(
                "companyid" => $company_id,
                "enrollNumber" => $employee_id,
                "date(signinDate)" => $current_date
            ))
            ->get("devicelog")
            ->result();
    }

    public function _checkLeave($employee_id, $current_date)
    {
        $company_id = 101;

        return $this->db
            ->where(array(
                "company_id" => $company_id,
                "employee_id" => $employee_id,
                "status" => "APPROVED",
            ))
            ->group_start()
            // ->where("{$current_date} BETWEEN date(leave_from) and date(leave_to)")
            ->or_where("date(leave_from)", $current_date)
            ->or_where("date(leave_to)", $current_date)
            ->group_end()
            ->get("leave")
            ->row();
    }

    public function _checkHoliday($current_date)
    {
        $company_id = 101;
        return $this->db
            ->where(array(
                "company_id" => $company_id,
                "date(leave_date)" => $current_date,
            ))
            ->get("holiday")
            ->num_rows();
    }

    public function _checkForEmptyLog($empid, $current_date, $shift)
    {
        $leave = $this->_checkLeave($empid, $current_date);
        $holiday = $this->_checkHoliday($current_date);
        $shift_weekend_list = $shift->weekend;
        $day_of_week = date('w', strtotime($current_date));
        $weekend_arr = json_decode($shift_weekend_list, true);

        if (is_array($weekend_arr)) {
            $weekend = $weekend_arr['weekend'];
        } else {
            $weekend = array();
        }

        if (in_array($day_of_week, $weekend) || $day_of_week == $shift_weekend_list) {
            $remark = "W";
            $class = "weekend";
        } else if ($holiday) {
            $remark = "H";
            $class = "holiday";
        } else if ($leave) {
            $leave_type = $this->db
                ->where("leave_typeID", $leave->leave_type)
                ->get("leave_type")
                ->row();
            $remark = (@$leave_type->leave_code != '') ? $leave_type->leave_code : 'OL';
            $class = 'leave';
        } else {
            $remark = 'A';
            $class = 'absent';
        }

        $out_checkin = date("Y-m-d", strtotime($current_date));
        $out_arr = explode("-", $out_checkin);


        if (!$this->employee_model->check_ret_present($empid, $current_date)) {
            $remark = 'A';
            $class = 'absent';
        }


        $checkin = strtotime($current_date);
        $present_days = '0';

        return array(
            "remark" => $remark,
            "class" => $class,
            "checkin" => $checkin,
            "present_days" => $present_days,
        );
    }

    public function _getLeaveType($leave)
    {
        $leave_type = $this->db->where("leave_typeID", $leave->leave_type)->get("leave_type")->row();
        $remark = (@$leave_type->leave_code != '') ? $leave_type->leave_code : 'OL';
        $class = 'leave';

        return array(
            "remark" => $remark,
            "class" => $class,
        );
    }
}
