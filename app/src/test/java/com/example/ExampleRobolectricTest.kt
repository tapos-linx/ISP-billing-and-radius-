package com.example

import android.content.Context
import androidx.test.core.app.ApplicationProvider
import com.example.data.repository.ProductionArtifactsRepository
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertTrue
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

@RunWith(RobolectricTestRunner::class)
@Config(sdk = [36])
class ExampleRobolectricTest {

  @Test
  fun `read string from context`() {
    val context = ApplicationProvider.getApplicationContext<Context>()
    val appName = context.getString(R.string.app_name)
    assertEquals("ISP Billing", appName)
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
}
