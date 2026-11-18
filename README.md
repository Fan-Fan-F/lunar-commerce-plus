# Lunar Commerce Plus

> 基于 Laravel 的企业级现代化无头电商核心系统与跨境商业增强引擎。
> Modern headless e-commerce core system and cross-border commercial engine built for Laravel.

---

## 简体中文

### 简介
Lunar Commerce Plus 是基于 `lunarphp/lunar` 深度增强与二次开发的无头电商核心套件。针对跨国独立站、出海电商与高并发交易场景，本项目重点修复了核心购物车与税费计算中的边界缺陷，重构了税区空指针防御，增强了订单创建管道的折让与约束健壮性，并新增了原生跨境支付通道适配器（支持多币种清算、实时汇率、合规审查与风控元数据），为 Laravel 开发者提供稳定、灵活且开箱即用的全球化电商底层架构。

### 功能与改进点（对应 CHANGES.md）

1. **购物车税费计算临时覆盖 Bug 修复与空税区空指针防护**
   - **移除中间状态污染**：修复 `CalculateTax` 循环遍历购物车明细行时第 51 行错误将单行税额覆盖到购物车全局 `taxTotal` 的缺陷，避免中间监听器获取失真数据。
   - **空税区防御处理**：在 `SystemTaxDriver` 中针对收货地址未匹配到税区且无系统默认税区的极端边界加入空值保护，安全返回空税费明细而不是抛出致命错误；对含税反算增加除零防护。
   - **空购物车安全初始化**：完善无商品行及无运费状态下购物车各层级金额的安全初始化与兜底。

2. **新增跨境支付通道适配器与多币种/合规元数据支持**
   - **跨境支付驱动实现**：新增 `Lunar\PaymentTypes\CrossBorderPayment` 驱动，无缝接入 `Lunar\Facades\Payments` 门面系统。
   - **多币种结算与外汇（FX）追踪**：支持记录结算币种（settlement_currency）、换汇汇率（exchange_rate）及外汇交易手续费（fx_fee）。
   - **海关与跨境监管合规**：内置海关申报跟踪编号（customs_reference）、始发国（origin_country）、目的国（destination_country）及支付方案（如国际信用卡、跨境电子钱包、电汇）元数据支持。
   - **跨境风控核验**：通过 `getPaymentChecks()` 自动提供跨币种一致性、跨境路线完整性及海关申报凭单风控自检。

3. **订单创建管道金额与折让边界健壮性优化**
   - **字段空值兜底保障**：在 `FillOrderFromCart` 中为 `discount_total`、`shipping_total`、`tax_total` 等提供安全整数保底，避免严格数据库模式下触发 NOT NULL 约束异常。
   - **行级折让溢出防护**：在 `CreateOrderLines` 中强制对订单行的折让与税费赋予整型保底，防止由于浮点精度或折让超额导致的负向数据穿透。

4. **价格与金额格式化边界处理与负值退款展示优化**
   - **负值退款金额安全格式化**：优化 `DefaultPriceFormatter` 对负数金额（如退款单、折让调整）的处理，防止多语言环境下货币符号与负号排布错位。
   - **精度消除正则保护**：修复 NumberFormatter 在特殊货币符号下去除尾部无效 0 时的正则异常，并加入非字符串类型防御。

### 快速开始

#### 环境要求
- PHP 8.3+
- Composer 2.0+
- SQLite 3 / MySQL 8.0+ / PostgreSQL 14+
- BCMath & Intl 扩展

#### 安装步骤

1. 克隆代码库：
```bash
git clone https://github.com/Fan-Fan-F/lunar-commerce-plus.git
cd lunar-commerce-plus
```

2. 安装 Composer 依赖：
```bash
composer install
```

3. 运行核心模块单元测试：
```bash
vendor/bin/pest tests/core/Unit/PaymentTypes/CrossBorderPaymentTypeTest.php
vendor/bin/pest tests/core/Feature/Drivers/SystemTaxDriverTest.php
vendor/bin/pest tests/core/Unit/DataTypes/PriceTest.php
```

#### 配置示例：启用跨境支付通道
在 `config/lunar/payments.php` 中注册跨境驱动：
```php
'types' => [
    'cross_border' => [
        'driver' => 'cross_border',
        'authorized' => 'cross-border-paid',
        'settlement_currency' => 'USD',
        'default_scheme' => 'international_card',
    ],
],
```

在结算流程中发起跨境收单：
```php
use Lunar\Facades\Payments;

$response = Payments::driver('cross_border')
    ->cart($cart)
    ->withData([
        'settlement_currency' => 'EUR',
        'exchange_rate' => 0.92,
        'fx_fee' => 350,
        'customs_reference' => 'CUST-2026-X889',
        'origin_country' => 'DE',
        'destination_country' => 'US',
        'payment_scheme' => 'alipay_cross_border',
    ])
    ->authorize();
```

### 来源与致谢
本项目基于 [lunar](https://github.com/lunarphp/lunar) 二次开发，原项目由 lunarphp 以 MIT 许可证开源。

### 许可证
沿用原许可证，全文见根目录 [LICENSE.md](LICENSE.md)。

---

## English

### Introduction
Lunar Commerce Plus is an enhanced secondary development of `lunarphp/lunar`, the modern headless e-commerce core for Laravel. Designed for cross-border independent stores and multi-currency global transactions, this project resolves boundary defects in cart tax calculations, adds null-safe guards for unconfigured tax zones, hardens order creation pipelines against database constraint anomalies, and introduces a native cross-border payment adapter with multi-currency FX settlement and regulatory compliance tracking.

### Key Enhancements (Corresponding to CHANGES.md)

1. **Cart Tax Calculation Fix & Null TaxZone Protection**
   - **Eliminated Interim State Corruption**: Fixed line 51 in `CalculateTax` where single line tax erroneously overwrote cart-level `taxTotal` during line iteration.
   - **Null TaxZone Fallback**: Safe guard in `SystemTaxDriver` returning an empty `TaxBreakdown` when addresses fall outside tax zones and no default exists, preventing fatal null property access.
   - **Zero Division Protection**: Guard against division-by-zero when prices include tax and total percentage is invalid.

2. **Cross-Border Payment Adapter & Multi-Currency/Compliance Metadata**
   - **Native Cross-Border Driver**: Introduced `Lunar\PaymentTypes\CrossBorderPayment` accessible through `Payments::driver('cross_border')`.
   - **FX & Settlement Tracking**: Captures `settlement_currency`, `exchange_rate`, and `fx_fee`.
   - **Customs & Regulatory Metadata**: Records `customs_reference`, `origin_country`, `destination_country`, and payment scheme.
   - **Automated Risk Checks**: Provides `getPaymentChecks()` for cross-border currency alignment, route consistency, and customs reference validation.

3. **Order Pipeline Constraint Hardening**
   - Guaranteed non-null integer defaults for `discount_total`, `shipping_total`, and `tax_total` in `FillOrderFromCart` and `CreateOrderLines`, preventing NOT NULL constraint errors across strict database engines.

4. **Price Formatting & Negative Refund Boundary Improvements**
   - Hardened `DefaultPriceFormatter` for negative price values (refunds and discount adjustments), ensuring proper locale placement of minus signs, currency symbols, and trailing zeros.

### Quick Start

#### Requirements
- PHP 8.3+
- Composer 2.0+
- SQLite 3 / MySQL 8.0+ / PostgreSQL 14+
- BCMath & Intl extensions

#### Installation
```bash
git clone https://github.com/Fan-Fan-F/lunar-commerce-plus.git
cd lunar-commerce-plus
composer install
```

#### Running Tests
```bash
vendor/bin/pest tests/core/Unit/PaymentTypes/CrossBorderPaymentTypeTest.php
vendor/bin/pest tests/core/Feature/Drivers/SystemTaxDriverTest.php
vendor/bin/pest tests/core/Unit/DataTypes/PriceTest.php
```

### Source and Acknowledgements
This project is an enhanced secondary development based on [lunar](https://github.com/lunarphp/lunar), originally open-sourced by lunarphp under the MIT License.

### License
This project inherits the original MIT License. See [LICENSE.md](LICENSE.md) for full text.
