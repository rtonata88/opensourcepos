INSERT INTO `ospos_permissions` (`permission_id`, `module_id`) VALUES
('sales_returns', 'sales');

INSERT INTO `ospos_grants` (`permission_id`, `person_id`, `menu_group`) VALUES
('sales_returns', 1, '--');

ALTER TABLE `ospos_sales`
  ADD COLUMN `return_approved_by` INT(10) NULL DEFAULT NULL;

ALTER TABLE `ospos_sales`
  ADD CONSTRAINT `ospos_sales_ibfk_return_approved_by`
  FOREIGN KEY (`return_approved_by`) REFERENCES `ospos_employees` (`person_id`);
