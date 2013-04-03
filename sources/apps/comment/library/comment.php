<?php
defined ( 'IN_YUNCMS' ) or exit ( 'No permission resources.' );
/**
 *
 * @author Tongle Xu <xutongle@gmail.com> 2012-6-13
 * @copyright Copyright (c) 2003-2103 yuncms.net
 * @license http://leaps.yuncms.net
 * @version $Id: comment.php 273 2013-04-01 09:30:54Z 85825770@qq.com $
 */
class comment {
	// 数据库连接
	private $comment_db, $comment_data_db, $comment_table_db, $comment_check_db;
	public $msg_code = 0;
	public function __construct() {
		$this->comment_db = Loader::model ( 'comment_model' );
		$this->comment_data_db = Loader::model ( 'comment_data_model' );
		$this->comment_table_db = Loader::model ( 'comment_table_model' );
		$this->comment_check_db = Loader::model ( 'comment_check_model' );
	}

	/**
	 * 添加评论
	 *
	 * @param string $commentid 评论ID
	 * @param array $data 内容数组应该包括array('userid'=>用户ID，'username'=>用户名,'content'=>内容)
	 * @param string $id 回复评论的内容
	 * @param string $title 文章标题
	 * @param string $url 文章URL地址
	 */
	public function add($commentid, $data, $id = '', $title = '', $url = '') {
		// 开始查询评论这条评论是否存在。
		$title = new_addslashes ( $title );
		if (! $comment = $this->comment_db->where( array ('commentid' => $commentid ))->field( 'tableid, commentid' )->find()) { // 评论不存在
		                             // 取得当前可以使用的内容数据表
			$r = $this->comment_table_db->field ('tableid, total')->order('tableid desc' )->find();
			$tableid = $r ['tableid'];
			if ($r ['total'] >= 1000000) {
				// 当上一张数据表存的数据已经达到1000000时，创建新的数据存储表，存储数据。
				if (! $tableid = $this->comment_table_db->creat_table ()) {
					$this->msg_code = 4;
					return false;
				}
			}
			// 新建评论到评论总表中。
			$comment_data = array ('commentid' => $commentid,'tableid' => $tableid );
			if (! empty ( $title )) $comment_data ['title'] = $title;
			if (! empty ( $url )) $comment_data ['url'] = $url;
			if (! $this->comment_db->insert ( $comment_data )) {
				$this->msg_code = 5;
				return false;
			}
		} else { // 评论存在时
			$tableid = $comment ['tableid'];
		}
		if (empty ( $tableid )) {
			$this->msg_code = 1;
			return false;
		}
		// 为数据存储数据模型设置 数据表名。
		$this->comment_data_db->table_name ( $tableid );
		// 检查数据存储表。
		if (! $this->comment_data_db->table_exists ( 'comment_data_' . $tableid )) {
			// 当存储数据表不存时，尝试创建数据表。
			if (! $tableid = $this->comment_table_db->creat_table ( $tableid )) {
				$this->msg_code = 2;
				return false;
			}
		}
		// 向数据存储表中写入数据。
		$data ['commentid'] = $commentid;
		$data ['ip'] = IP;
		$data ['status'] = 1;
		$data ['creat_at'] = TIME;
		// 对评论的内容进行关键词过滤。
		$data ['content'] = strip_tags ( $data ['content'] );
		$badword = Loader::model ( 'badword_model' );
		$data ['content'] = $badword->replace_badword ( $data ['content'] );
		if ($id) {
			$r = $this->comment_data_db->getby_id ($id );
			if ($r) {
				if ($r ['reply']) {
					$data ['content'] = '<div class="content">' . str_replace ( '<span></span>', '<span class="blue f12">' . $r ['username'] . ' ' . L ( 'chez' ) . ' ' . Format::date ( $r ['creat_at'], 1 ) . L ( 'release' ) . '</span>', $r ['content'] ) . '</div><span></span>' . $data ['content'];
				} else {
					$data ['content'] = '<div class="content"><span class="blue f12">' . $r ['username'] . ' ' . L ( 'chez' ) . ' ' . Format::date ( $r ['creat_at'], 1 ) . L ( 'release' ) . '</span><pre>' . $r ['content'] . '</pre></div><span></span>' . $data ['content'];
				}
				$data ['reply'] = 1;
			}
		}
		// 判断站点是否需要审核
		$site = S ( 'common/comment' );
		if ($site ['check']) $data ['status'] = 0;
		if ($comment_data_id = $this->comment_data_db->insert ( $data, true )) {
			// 需要审核，插入到审核表
			if ($data ['status'] == 0) {
				$this->comment_check_db->insert ( array ('comment_data_id' => $comment_data_id,'tableid' => $tableid ) );
			} elseif (! empty ( $data ['userid'] ) && ! empty ( $site ['add_point'] ) && application_exists ( 'pay' )) { // 不需要审核直接给用户添加积分
				Loader::lib ( 'pay:receipts', false );
				receipts::point ( $site ['add_point'], $data ['userid'], $data ['username'], '', 'selfincome', 'Comment' );
			}
			// 开始更新数据存储表数据总条数
			$this->comment_table_db->edit_total ( $tableid, '+=1' );
			// 开始更新评论总表数据总数
			$sql ['lastupdate'] = TIME;
			// 只有在评论通过的时候才更新评论主表的评论数
			if ($data ['status'] == 1) {
				$sql ['total'] = '+=1';
			}
			$this->comment_db->where(array ('commentid' => $commentid ))->update ( $sql );
			if ($site ['check'])
				$this->msg_code = 7;
			else
				$this->msg_code = 0;
			return true;
		} else {
			$this->msg_code = 3;
			return false;
		}
	}

	/**
	 * 支持评论
	 *
	 * @param integer $commentid 评论ID
	 * @param integer $id 内容ID
	 */
	public function support($commentid, $id) {
		if ($data = $this->comment_db->where ( array ('commentid' => $commentid ))->field( 'tableid' )->find()) {
			$this->comment_data_db->table_name ( $data ['tableid'] );
			if ($this->comment_data_db->where(array ('id' => $id ))->update ( array ('support' => '+=1' ) )) {
				$this->msg_code = 0;
				return true;
			} else {
				$this->msg_code = 3;
				return false;
			}
		} else {
			$this->msg_code = 6;
			return false;
		}
	}

	/**
	 * 更新评论的状态
	 *
	 * @param string $commentid 评论ID
	 * @param integer $id 内容ID
	 * @param integer $status 状态{1:通过 ,0:未审核， -1:不通过,将做删除操作}
	 */
	public function status($commentid, $id, $status = -1) {
		if (! $comment = $this->comment_db->where ( array ('commentid' => $commentid ))->field('tableid, commentid' )->find()) {
			$this->msg_code = 6;
			return false;
		}

		// 为数据存储数据模型设置 数据表名。
		$this->comment_data_db->table_name ( $comment ['tableid'] );
		if (! $comment_data = $this->comment_data_db->where ( array ('id' => $id,'commentid' => $commentid ) )->find()) {
			$this->msg_code = 6;
			return false;
		}
		// 读取评论的站点配置信息
		$site = S ( 'common/comment' );

		if ($status == 1) { // 通过的时候
			$sql ['total'] = '+=1';
			// 当评论被设置为通过的时候，更新评论总表的数量。
			$this->comment_db->where(array ('commentid' => $comment ['commentid'] ))->update ( $sql );
			// 更新评论内容状态
			$this->comment_data_db->where(array ('id' => $id,'commentid' => $commentid ))->update ( array ('status' => $status ) );

			// 当评论用户ID不为空，而且站点配置了积分添加项，支付模块也存在的时候，为用户添加积分。
			if (! empty ( $comment_data ['userid'] ) && ! empty ( $site ['add_point'] ) && application_exists ( 'pay' )) {
				Loader::lib ( 'pay:receipts', false );
				receipts::point ( $site ['add_point'], $comment_data ['userid'], $comment_data ['username'], '', 'selfincome', 'Comment' );
			}
		} elseif ($status == - 1) { // 删除数据
		                            // 如果数据原有状态为已经通过，需要删除评论总表中的总数
			if ($comment_data ['status'] == 1) {
				$sql ['total'] = '-=1';
				$this->comment_db->where(array ('commentid' => $comment ['commentid'] ))->update ( $sql );
			}

			// 删除存储表的数据
			$this->comment_data_db->where(array ('id' => $id,'commentid' => $commentid ))->delete (  );
			// 删除存储表总数记录
			$this->comment_table_db->edit_total ( $comment ['tableid'], '-=1' );

			// 当评论ID不为空，站点配置了删除的点数，支付模块存在的时候，删除用户的点数。
			if (! empty ( $comment_data ['userid'] ) && ! empty ( $site ['del_point'] ) && application_exists ( 'pay' )) {
				Loader::lib ( 'pay:receipts', false );
				$op_userid = cookie ( 'userid' );
				$op_username = cookie ( 'admin_username' );
				spend::point ( $site ['del_point'], L ( 'comment_point_del', '', 'comment' ), $comment_data ['userid'], $comment_data ['username'], $op_userid, $op_username );
			}
		}

		// 删除审核表中的数据
		$this->comment_check_db->where(array ('comment_data_id' => $id ))->delete (  );

		$this->msg_code = 0;
		return true;
	}

	/**
	 * 删除评论
	 *
	 * @param string $commentid 评论ID
	 * @param intval $id 内容ID
	 * @param intval $catid 栏目ID
	 */
	public function del($commentid, $id, $catid) {
		if ($commentid != id_encode ( 'content_' . $catid, $id )) return false;
		// 循环评论内容表删除commentid的评论内容
		for($i = 1;; $i ++) {
			$table = 'comment_data_' . $i; // 构建评论内容存储表名
			if ($this->comment_data_db->table_exists ( $table )) { // 检查构建的表名是否存在，如果存在执行删除操作
				$this->comment_data_db->table_name ( $i );
				$this->comment_data_db->where(array ('commentid' => $commentid ))->delete (  );
			} else { // 不存在，则退出循环
				break;
			}
		}
		$this->comment_db->where(array ('commentid' => $commentid ))->delete (  ); // 删除评论主表的内容
		return true;
	}

	/**
	 * 获取报错的详细信息。
	 */
	public function get_error() {
		$msg = array ('0' => L ( 'operation_success' ),'1' => L ( 'coment_class_php_1' ),'2' => L ( 'coment_class_php_2' ),'3' => L ( 'coment_class_php_3' ),'4' => L ( 'coment_class_php_4' ),'5' => L ( 'coment_class_php_5' ),'6' => L ( 'coment_class_php_6' ),'7' => L ( 'coment_class_php_7' ) );
		return $msg [$this->msg_code];
	}
}