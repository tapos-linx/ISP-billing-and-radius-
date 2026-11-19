package com.example

import android.content.Context
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithTag
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.test.core.app.ApplicationProvider
import com.example.data.repository.ProductionArtifactsRepository
import com.example.ui.theme.MyApplicationTheme
import com.example.viewmodel.IspArchitectViewModel
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36])
class ExampleRobolectricTest {

  @get:Rule
  val composeTestRule = createComposeRule()

  @Test
  fun `read string from context`() {
    val context = ApplicationProvider.getApplicationContext<Context>()
    val appName = context.getString(R.string.app_name)
    assertEquals("Carrier Billing", appName)
  }

  @Test
  fun `verify deployment matrix contains all 5 stages`() {
    val stages = ProductionArtifactsRepository.stages
    assertEquals(5, stages.size)

    val stage1 = ProductionArtifactsRepository.getStage(1)
    assertNotNull(stage1)
    assertTrue(stage1!!.targetSla.contains("<10ms"))

    val stage3 = ProductionArtifactsRepository.getStage(3)
    assertNotNull(stage3)
    assertTrue(stage3!!.targetSla.contains("<500ms"))

    val stage4 = ProductionArtifactsRepository.getStage(4)
    assertNotNull(stage4)
    assertTrue(stage4!!.targetSla.contains("<3s"))
  }

  @Test
  fun `verify carrier edition tabs and cgnat lookup`() {
    val vm = IspArchitectViewModel()
    val matched = vm.reverseLookupCgnat("103.112.40.2", 1600)
    assertNotNull(matched)
    assertEquals("tapos_test", matched!!.username)

    composeTestRule.setContent {
      MyApplicationTheme {
        MainAppScaffold(viewModel = vm)
      }
    }
    composeTestRule.waitForIdle()
    composeTestRule.onNodeWithTag("nav_tab_noc").assertIsDisplayed()
    composeTestRule.onNodeWithTag("nav_tab_subscribers").performClick()
    composeTestRule.waitForIdle()
    composeTestRule.onNodeWithTag("subscriber_search_input").assertIsDisplayed()

    composeTestRule.onNodeWithTag("nav_tab_mfs").performClick()
    composeTestRule.waitForIdle()
    composeTestRule.onNodeWithTag("mfs_dispatch_button").performScrollTo().assertIsDisplayed()

    composeTestRule.onNodeWithTag("nav_tab_coa").performClick()
    composeTestRule.waitForIdle()
    composeTestRule.onNodeWithTag("send_pod_button").performScrollTo().assertIsDisplayed()
  }
}
