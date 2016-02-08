SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0;
SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;
SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='TRADITIONAL,ALLOW_INVALID_DATES';

DROP SCHEMA IF EXISTS `pmu` ;
CREATE SCHEMA IF NOT EXISTS `pmu` DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci ;
USE `pmu` ;

-- -----------------------------------------------------
-- Table `pmu`.`pmu_hyppodrome`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `pmu`.`pmu_hyppodrome` (
  `pmu_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `pmu_name` TEXT NOT NULL,
  PRIMARY KEY (`pmu_id`))
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `pmu`.`pmu_course`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `pmu`.`pmu_course` (
  `pmu_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `pmu_course_num` TINYINT UNSIGNED NOT NULL,
  `pmu_reunion_num` TINYINT UNSIGNED NOT NULL,
  `pmu_name` TEXT NOT NULL,
  `pmu_date` DATE NOT NULL,
  `pmu_horaire` TIME NOT NULL,
  `pmu_type` VARCHAR(1) NOT NULL,
  `pmu_distance` INT UNSIGNED NULL,
  `pmu_hyppodrome_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`pmu_id`),
  INDEX `pmu_course_identifier` (`pmu_course_num` ASC, `pmu_reunion_num` ASC, `pmu_date` ASC),
  INDEX `pmu_course_type` (`pmu_type` ASC),
  INDEX `fk_pmu_course_1_idx` (`pmu_hyppodrome_id` ASC),
  CONSTRAINT `fk_pmu_course_1`
    FOREIGN KEY (`pmu_hyppodrome_id`)
    REFERENCES `pmu`.`pmu_hyppodrome` (`pmu_id`)
    ON DELETE NO ACTION
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `pmu`.`pmu_entraineur`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `pmu`.`pmu_entraineur` (
  `pmu_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `pmu_name` TEXT NOT NULL,
  PRIMARY KEY (`pmu_id`))
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `pmu`.`pmu_cheval`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `pmu`.`pmu_cheval` (
  `pmu_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `pmu_name` TEXT NOT NULL,
  PRIMARY KEY (`pmu_id`))
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `pmu`.`pmu_jockey`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `pmu`.`pmu_jockey` (
  `pmu_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `pmu_name` TEXT NOT NULL,
  PRIMARY KEY (`pmu_id`))
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `pmu`.`pmu_concurent`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `pmu`.`pmu_concurent` (
  `pmu_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `pmu_course_id` INT UNSIGNED NOT NULL,
  `pmu_cheval_id` INT UNSIGNED NOT NULL,
  `pmu_jockey_id` INT UNSIGNED NOT NULL,
  `pmu_entraineur_id` INT UNSIGNED NOT NULL,
  `pmu_numero` TINYINT UNSIGNED NOT NULL,
  `pmu_position` VARCHAR(5) NULL,
  `pmu_cote` FLOAT UNSIGNED NULL,
  PRIMARY KEY (`pmu_id`),
  INDEX `fk_pmu_concurent_1_idx` (`pmu_course_id` ASC),
  INDEX `fk_pmu_concurent_2_idx` (`pmu_entraineur_id` ASC),
  INDEX `fk_pmu_concurent_3_idx` (`pmu_cheval_id` ASC),
  INDEX `fk_pmu_concurent_4_idx` (`pmu_jockey_id` ASC),
  CONSTRAINT `fk_pmu_concurent_1`
    FOREIGN KEY (`pmu_course_id`)
    REFERENCES `pmu`.`pmu_course` (`pmu_id`)
    ON DELETE CASCADE
    ON UPDATE CASCADE,
  CONSTRAINT `fk_pmu_concurent_2`
    FOREIGN KEY (`pmu_entraineur_id`)
    REFERENCES `pmu`.`pmu_entraineur` (`pmu_id`)
    ON DELETE NO ACTION
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_pmu_concurent_3`
    FOREIGN KEY (`pmu_cheval_id`)
    REFERENCES `pmu`.`pmu_cheval` (`pmu_id`)
    ON DELETE NO ACTION
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_pmu_concurent_4`
    FOREIGN KEY (`pmu_jockey_id`)
    REFERENCES `pmu`.`pmu_jockey` (`pmu_id`)
    ON DELETE NO ACTION
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


-- -----------------------------------------------------
-- Table `pmu`.`pmu_turfomania`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `pmu`.`pmu_turfomania` (
  `pmu_turfomania_id` INT UNSIGNED NOT NULL,
  `pmu_course_id` INT UNSIGNED NULL,
  `pmu_hyppodrome_id` INT UNSIGNED NULL,
  `pmu_cheval_id` INT UNSIGNED NULL,
  `pmu_entraineur_id` INT UNSIGNED NULL,
  `pmu_jockey_id` INT UNSIGNED NULL,
  UNIQUE INDEX `idx_turfomania_id` (`pmu_turfomania_id` ASC, `pmu_course_id` ASC, `pmu_hyppodrome_id` ASC, `pmu_cheval_id` ASC, `pmu_entraineur_id` ASC, `pmu_jockey_id` ASC),
  INDEX `fk_pmu_turfomania_1_idx` (`pmu_course_id` ASC),
  INDEX `fk_pmu_turfomania_2_idx` (`pmu_hyppodrome_id` ASC),
  INDEX `fk_pmu_turfomania_3_idx` (`pmu_cheval_id` ASC),
  INDEX `fk_pmu_turfomania_5_idx` (`pmu_jockey_id` ASC),
  INDEX `fk_pmu_turfomania_4_idx` (`pmu_entraineur_id` ASC),
  CONSTRAINT `fk_pmu_turfomania_1`
    FOREIGN KEY (`pmu_course_id`)
    REFERENCES `pmu`.`pmu_course` (`pmu_id`)
    ON DELETE NO ACTION
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_pmu_turfomania_2`
    FOREIGN KEY (`pmu_hyppodrome_id`)
    REFERENCES `pmu`.`pmu_hyppodrome` (`pmu_id`)
    ON DELETE NO ACTION
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_pmu_turfomania_3`
    FOREIGN KEY (`pmu_cheval_id`)
    REFERENCES `pmu`.`pmu_cheval` (`pmu_id`)
    ON DELETE NO ACTION
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_pmu_turfomania_4`
    FOREIGN KEY (`pmu_entraineur_id`)
    REFERENCES `pmu`.`pmu_entraineur` (`pmu_id`)
    ON DELETE NO ACTION
    ON UPDATE NO ACTION,
  CONSTRAINT `fk_pmu_turfomania_5`
    FOREIGN KEY (`pmu_jockey_id`)
    REFERENCES `pmu`.`pmu_jockey` (`pmu_id`)
    ON DELETE NO ACTION
    ON UPDATE NO ACTION)
ENGINE = InnoDB;


SET SQL_MODE=@OLD_SQL_MODE;
SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;
SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS;
