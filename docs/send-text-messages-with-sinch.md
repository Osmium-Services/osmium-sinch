---
title: Send text messages with Sinch
tags: sms, text messages, sinch, notifications, orders
---

**Sinch** lets your site send text messages, such as order updates. The service only sends. Whatever wants to text, such as the shop, asks it to. Each text is charged by Sinch.

## Set it up

1. Open **Sinch SMS Settings**.
2. Enter your **Service plan ID** and **API token** from the SMS APIs section of your Sinch Build dashboard, and the **Region** your service plan was created in.
3. Enter the **Sender**: a Sinch number such as +447700900123, or an alphanumeric sender ID of up to 11 letters, digits or spaces. Alphanumeric IDs are not accepted in every country and cannot receive replies.
4. Tick to send SMS through Sinch and press **Save Settings**.

## Test it

Under **Send a test text**, enter a **Mobile number** and press **Send test text**. Save first, because it uses the saved settings. It works even while switched off, and is charged like a real text.

## Turn on order texts

In the **Osmium Shop Settings**, under **Customer Notifications**, choose texts for order updates. Only one SMS service can be active at a time. Orders with no phone number are skipped.
