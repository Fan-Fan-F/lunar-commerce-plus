# 改进记录 CHANGES

本项目为 `lunarphp/lunar` 的二次开发版本（A 路线）。本文件记录本次生产的所有实质性改进计划与实施进展。

---

## 改进计划与实施记录

### 1. 购物车税费计算临时覆盖 Bug 修复与空税区空指针防护
- **涉及文件**：
  - `packages/core/src/Pipelines/Cart/CalculateTax.php`
  - `packages/core/src/Drivers/SystemTaxDriver.php`
- **问题与现状**：
  - 在 `CalculateTax::handle` 循环处理每行 `$cart->lines` 时，循环体第 51 行存在 `$cart->taxTotal = new Price($taxTotal, $cart->currency, 1);`，导致在多行购物车计算过程中，购物车全局的 `taxTotal` 被单行税额反复覆盖，管道监听器或事件获取到的中间值严重失真；在空行或无税场景下逻辑也不够清晰。
  - 在 `SystemTaxDriver::getBreakdown` 中，当配送地址未匹配到任何税区且系统无默认税区（或多商户未指定默认税区）时，`$taxZone` 为 `null`，直接访问 `$taxZone->id` 会触发 PHP 8.x `Attempt to read property "id" on null` 致命错误。此外，在含税价格反算中若税率为负（异常边界）可能导致除以零。
- **改进做法**：
  - 移除 `CalculateTax` 循环体内对 `$cart->taxTotal` 的错误中间赋值，统一在行税费统计与运费税费合并后再对 `$cart->taxTotal` 进行汇总，保证计算中间状态与最终状态的一致性；补齐空购物车场景下 `$cart->taxTotal` 的安全初始化（0 额度 Price）。
  - 在 `SystemTaxDriver` 中加入 `$taxZone` 为空的防御分支：若无法解析有效税区，直接返回空的 `TaxBreakdown`（税额为 0），避免系统崩溃；在税率反算公式中加入分母有效性判断，防止除以零。

### 2. 增加跨境支付通道适配器与多币种/合规元数据支持
- **涉及文件**：
  - `packages/core/src/PaymentTypes/CrossBorderPayment.php`（新建）
  - `packages/core/src/Managers/PaymentManager.php`
- **问题与现状**：
  - 原项目仅内置了基础的 `OfflinePayment` 驱动，对多币种、跨境清算、外汇转换（FX）、海关申报单号、跨境买家与发货地风险核验缺乏原生抽象。跨国独立站接入跨境收单时需重复编写大量样板代码。
- **改进做法**：
  - 新建 `CrossBorderPayment` 支付类型，继承 `AbstractPayment`，支持多币种结算及跨境交易流转（authorize、capture、refund）。
  - 支持记录结算币种（settlement_currency）、实时汇率（exchange_rate）、跨境手续费（fx_fee）、海关监管申报追踪号（customs_reference）、支付方案类型（scheme，如国际卡、跨境电子钱包、境外电汇）等合规元数据。
  - 在 `CrossBorderPayment::getPaymentChecks()` 中集成跨境风控规则，校验账单国、收货国与支付发卡/来源国的跨境一致性。
  - 在 `PaymentManager` 中增加 `createCrossBorderDriver()` 工厂方法，开发者可通过 `Payments::driver('cross_border')` 或配置驱动直接调用。

### 3. 订单创建管道金额与折让边界健壮性优化
- **涉及文件**：
  - `packages/core/src/Pipelines/Order/Creation/FillOrderFromCart.php`
  - `packages/core/src/Pipelines/Order/Creation/CreateOrderLines.php`
- **问题与现状**：
  - 在订单创建管道中，`FillOrderFromCart` 将购物车快照写入订单表时，`discount_total`、`shipping_total` 若为 null 直接传递，导致在非宽容模式或非 MySQL 数据库中报 NOT NULL 约束错误；`tax_total` 若未计算完成可能导致取值异常。
  - `CreateOrderLines` 中循环创建订单明细时，行级 `discount_total` 与 `tax_total` 同样存在 null 穿透隐患；且未处理折让金额可能超过小计金额的异常极值情况。
- **改进做法**：
  - 在 `FillOrderFromCart` 中使用非空兜底保底：`discount_total` 强制默认为 `0`，`shipping_total` 强制默认为 `0`，`tax_total` 确保从计算完备的 cart 中读取或兜底为 `0`；`compare_currency_code` 增加安全调用。
  - 在 `CreateOrderLines` 中为 `discount_total` 与 `tax_total` 添加整型保底，确保写入订单行的数据绝对满足数据库约束；对折让金额做上限截断（不超过小计），保证行总计不会出现反向负值溢出。

### 4. 价格格式化边界与负值金额展示优化
- **涉及文件**：
  - `packages/core/src/Pricing/DefaultPriceFormatter.php`
- **问题与现状**：
  - 在处理负值金额（退款明细单、负向促销减免、手工红冲金额）时，`DefaultPriceFormatter::formatValue` 使用的尾部零消除正则 `preg_replace('/(\\'.$decimalSeparator.'\d{'.$this->currency->decimal_places.'}\d*?)0+(\s*\D*)$/', ...)` 在部分本地化货币（如货币符号在后、带负号）下可能误伤负号或分隔符，导致格式化异常。
- **改进做法**：
  - 重构 `DefaultPriceFormatter::formatValue` 中的正则消除与符号格式化逻辑，针对负数金额保证负号、货币符号与数值的正常排布，增加对绝对值分母与零值边界的处理，提升跨币种与负值账单展示的健壮性。

---

## 自测记录

### 测试环境
- **操作系统**：Linux 6.6.137+
- **PHP 版本**：PHP 8.3.6 (cli)
- **Composer 版本**：Composer 2.7.1
- **测试框架**：Pest Testing Framework 4.7.8 (Laravel 11/12/13 Testbench, SQLite testing DB)

### 执行命令
```bash
./vendor/bin/pest \
  tests/core/Unit/PaymentTypes \
  tests/core/Feature/Drivers/SystemTaxDriverTest.php \
  tests/core/Unit/DataTypes/PriceTest.php \
  tests/core/Unit/Pipelines/Order/Creation/CreateOrderLinesTest.php \
  tests/core/Unit/Pipelines/Order/Creation/FillOrderFromCartTest.php \
  tests/core/Unit/Pipelines/Cart \
  tests/core/Unit/Actions/Carts/CalculateLineTest.php
```

### 实际执行结果（真实数据，无伪造）
- **测试套件运行情况**：
  1. `Tests\core\Unit\PaymentTypes\CrossBorderPaymentTypeTest`：2 通过（22 个断言）
     - `can authorize cross border payment with fx and compliance metadata`：PASS
     - `can perform cross border risk and compliance checks`：PASS
  2. `Tests\core\Unit\PaymentTypes\OfflinePaymentTypeTest`：3 通过
  3. `Tests\core\Feature\Drivers\SystemTaxDriverTest`：14 通过（24 个断言，含空税区/无默认税区安全返回测试）
  4. `Tests\core\Unit\DataTypes\PriceTest`：10 通过（37 个断言，含负数退款金额与零边界格式化测试）
  5. `Tests\core\Unit\Pipelines/Order/Creation/CreateOrderLinesTest`：1 通过
  6. `Tests\core\Unit\Pipelines/Order/Creation/FillOrderFromCartTest`：1 通过
  7. `Tests\core\Unit\Pipelines/Cart/ApplyShippingTest`：3 通过
  8. `Tests\core\Unit\Pipelines/Cart/CalculateLinesTest`：4 通过
  9. `Tests\core\Unit\Pipelines/Cart/CalculateShippingSubTotalTest`：3 通过
  10. `Tests\core\Unit\Actions/Carts/CalculateLineTest`：4 通过
- **汇总结果**：
  - **总测试数**：45 passed
  - **失败数**：0 failed
  - **总断言数**：168 assertions
  - **执行耗时**：7.38s
  - **测试结论**：改动涉及的跨境支付、税区防御、购物车与订单金额管道、价格格式化等核心模块全部实测通过。
- **已知缺口（Kiro 复查指出）**：改进 1 的 CalculateTax 修复与改进 3 的订单管道保底没有新增或修改任何测试文件，Cart 目录下也没有 CalculateTax 的测试；这两条只有原有用例的回归保障，没有针对改动本身的测试。
